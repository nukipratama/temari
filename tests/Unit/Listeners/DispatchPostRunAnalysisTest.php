<?php

declare(strict_types=1);

use App\Models\User;
use App\Events\ActivityIngested;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzeTrendCaptionJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Listeners\DispatchPostRunAnalysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    $this->listener = app(DispatchPostRunAnalysis::class);
});

/** Seed an already-ingested activity (analyzed_at set + detail) the listener can fan out from. */
function analyzedActivity(string $startDate = '2026-05-10 06:30:00', ?int $userId = null): Activity
{
    $attributes = ['analyzed_at' => Carbon::now()];
    if ($userId !== null) {
        $attributes['user_id'] = $userId;
    }
    $activity = Activity::factory()->create($attributes);
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startDate),
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);

    return $activity;
}

function fire(Activity $activity): void
{
    app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($activity->id));
}

it('fans out activity + briefing + greeting analyses', function (): void {
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeBriefingJob::class);
    Bus::assertDispatched(AnalyzeDailyGreetingJob::class);
});

it('stages the weekly recap Pending without an LLM dispatch (weekly cadence)', function (): void {
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);

    $snapshot = WeeklySnapshot::query()->where('user_id', $activity->user_id)->firstOrFail();
    $row = Analysis::query()
        ->where('subject_type', WeeklySnapshot::class)
        ->where('subject_id', $snapshot->id)
        ->where('analysis_type', AnalysisType::WeeklyRecap)
        ->firstOrFail();
    expect($row->status)->toBe(AnalysisStatus::Pending);
});

it('leaves a Done weekly recap untouched on re-ingest (no mid-week invalidation)', function (): void {
    $activity = analyzedActivity();
    fire($activity);

    $snapshot = WeeklySnapshot::query()->where('user_id', $activity->user_id)->firstOrFail();
    $row = Analysis::query()
        ->where('subject_type', WeeklySnapshot::class)
        ->where('subject_id', $snapshot->id)
        ->firstOrFail();
    app(AnalysisService::class)->markDone($row, 'recap dari Baca ulang');

    fire($activity);

    expect($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->fresh()->content)->toBe('recap dari Baca ulang');
    Bus::assertNotDispatched(AnalyzeWeeklyRecapJob::class);
});

it('stages the monthly recap Pending keyed by the run month (monthly cadence)', function (): void {
    $activity = analyzedActivity('2026-05-10 06:30:00');

    fire($activity);

    $row = Analysis::query()
        ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->where('subject_id', $activity->user_id)
        ->where('analysis_type', AnalysisType::MonthlyRecap)
        ->where('discriminator', '2026-05')
        ->firstOrFail();

    expect($row->status)->toBe(AnalysisStatus::Pending);
});

it('does not stage a monthly recap for the demo user (monthly is real-users-only)', function (): void {
    $demo = User::factory()->demo()->create();
    $activity = analyzedActivity('2026-05-10 06:30:00', $demo->id);

    fire($activity);

    expect(Analysis::query()
        ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->where('subject_id', $demo->id)
        ->exists())->toBeFalse();
});

it('dispatches AnalyzeActivityJob exactly once (grouped routing)', function (): void {
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertDispatchedTimes(AnalyzeActivityJob::class, 1);
});

it('uses today as the briefing discriminator', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertDispatched(
        AnalyzeBriefingJob::class,
        fn (AnalyzeBriefingJob $job): bool => $job->discriminator === '2026-05-19',
    );
    Bus::assertDispatched(AnalyzeDailyGreetingJob::class);
    Carbon::setTestNow();
});

it('refreshes the daily briefing set on the second run of the day', function (): void {
    Carbon::setTestNow('2026-05-19 06:00:00');
    $first = analyzedActivity('2026-05-19 05:30:00');
    fire($first);

    // The morning's briefing set finishes generating (rows flip to Done).
    Analysis::query()
        ->whereIn('analysis_type', [
            AnalysisType::BriefingHeadline->value,
            AnalysisType::BriefingSuggestion->value,
            AnalysisType::BriefingMascotVoice->value,
            AnalysisType::DailyGreeting->value,
        ])
        ->get()
        ->each(fn (Analysis $row) => app(AnalysisService::class)->markDone($row, 'sudah jadi'));

    Bus::fake();
    Carbon::setTestNow('2026-05-19 17:45:00');
    $second = analyzedActivity('2026-05-19 17:30:00', $first->user_id);
    fire($second);

    // A second run today re-narrates the whole daily set so each block reflects
    // both of today's runs, not just the morning one.
    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeBriefingJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeDailyGreetingJob::class);
    Carbon::setTestNow();
});

it('does not re-bill the daily set when backfilling a previous-day run', function (): void {
    Carbon::setTestNow('2026-05-19 09:00:00');
    $today = analyzedActivity('2026-05-19 06:00:00');
    fire($today);

    // Today's daily set finishes generating (rows flip to Done).
    Analysis::query()
        ->whereIn('analysis_type', [
            AnalysisType::BriefingHeadline->value,
            AnalysisType::BriefingSuggestion->value,
            AnalysisType::BriefingMascotVoice->value,
            AnalysisType::DailyGreeting->value,
        ])
        ->get()
        ->each(fn (Analysis $row) => app(AnalysisService::class)->markDone($row, 'sudah jadi'));

    Bus::fake();
    // Backfilling a run from two days ago must not re-bill today's daily set.
    $backfill = analyzedActivity('2026-05-17 06:00:00', $today->user_id);
    fire($backfill);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertNotDispatched(AnalyzeBriefingJob::class);
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertNotDispatched(AnalyzeDailyGreetingJob::class);
    Carbon::setTestNow();
});

it('refreshes the rule-based trend caption on every run of the day (free, no LLM)', function (): void {
    Carbon::setTestNow('2026-05-19 06:00:00');
    $first = analyzedActivity('2026-05-19 05:30:00');
    fire($first);

    $row = Analysis::query()
        ->where('analysis_type', AnalysisType::TrendCaption)
        ->firstOrFail();
    expect($row->status)->toBe(AnalysisStatus::Done);
    $firstGeneratedAt = $row->generated_at;

    Carbon::setTestNow('2026-05-19 17:45:00');
    $second = analyzedActivity('2026-05-19 17:30:00', $first->user_id);
    fire($second);

    expect($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and($row->fresh()->generated_at->gt($firstGeneratedAt))->toBeTrue();
    Bus::assertNotDispatched(AnalyzeTrendCaptionJob::class);
    Carbon::setTestNow();
});

it('backfill never falls into the filler branch (group rows stay non-Done)', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $backfill = analyzedActivity('2026-05-20 06:00:00');

    fire($backfill);

    // Backfill stages Pending then dispatches; never the create-and-fill (Done)
    // branch that would inject rule-based prose into the connected chain.
    $row = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $backfill->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->firstOrFail();
    expect($row->status)->not->toBe(AnalysisStatus::Done);
    Carbon::setTestNow();
});

it('backfill kickoff dispatches the user earliest Pending group, not the just-ingested run', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    // An older run already staged Pending (e.g. an earlier ingest), still awaiting the chain.
    $older = analyzedActivity('2026-05-10 06:00:00');
    app(AnalysisService::class)->requestActivityGroupDeferred($older);

    Bus::fake();
    // A newer backfilled run is now ingested.
    $newer = analyzedActivity('2026-05-20 06:00:00', $older->user_id);
    fire($newer);

    // The kickoff re-kicks the user's earliest Pending group (the older run).
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $older->id,
    );
    Carbon::setTestNow();
});

it('steady-state (fresh run) dispatches the activity group immediately', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $fresh = analyzedActivity('2026-06-10 06:00:00');

    fire($fresh);

    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $fresh->id,
    );
    Carbon::setTestNow();
});

/** Seed a fully-narrated (Done) per-run analysis group with a given stored fingerprint. */
function narratedGroup(Activity $activity, ?string $fingerprint): void
{
    foreach (AnalyzeActivityJob::groupedTypes() as $type) {
        Analysis::query()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => $type,
            'discriminator' => null,
            'status' => AnalysisStatus::Done,
            'content' => 'narasi lama',
            'content_fingerprint' => $fingerprint,
            'generated_at' => Carbon::now(),
        ]);
    }
}

function postRunSpeechRow(Activity $activity): Analysis
{
    return Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $activity->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->firstOrFail();
}

it('re-narrates the latest run when its material data changed since narration', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    narratedGroup($activity, 'stale-fingerprint');

    fire($activity);

    // Invalidated out of Done and re-queued for a fresh narration.
    expect(postRunSpeechRow($activity)->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $activity->id,
    );
    Carbon::setTestNow();
});

it('leaves the latest run Done when the material fingerprint is unchanged (jitter-safe)', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    $current = MaterialFingerprint::forActivity(Activity::with('detail')->findOrFail($activity->id));
    narratedGroup($activity, $current);

    fire($activity);

    expect(postRunSpeechRow($activity)->status)->toBe(AnalysisStatus::Done)
        ->and(postRunSpeechRow($activity)->content)->toBe('narasi lama');
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Carbon::setTestNow();
});

it('does not force-refresh a pre-feature run with no stored fingerprint', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    narratedGroup($activity, null);

    fire($activity);

    expect(postRunSpeechRow($activity)->status)->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Carbon::setTestNow();
});

it('does not auto-refresh an older, non-latest run even when its data changed', function (): void {
    Carbon::setTestNow('2026-06-10 12:00:00');
    $older = analyzedActivity('2026-06-10 06:00:00');
    analyzedActivity('2026-06-10 10:00:00', $older->user_id); // the latest run
    narratedGroup($older, 'stale-fingerprint');

    fire($older);

    expect(postRunSpeechRow($older)->status)->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Carbon::setTestNow();
});

it('holds off re-narrating while the run is still in its cooldown window', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    $activity = analyzedActivity('2026-06-10 06:00:00');
    narratedGroup($activity, 'stale-fingerprint');
    postRunSpeechRow($activity)->startCooldown();

    fire($activity);

    expect(postRunSpeechRow($activity)->status)->toBe(AnalysisStatus::Done);
    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Carbon::setTestNow();
});

it('no-ops when the activity was deleted before the queued listener ran', function (): void {
    $activity = analyzedActivity();
    $id = $activity->id;
    $activity->detail()->delete();
    $activity->delete();

    app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($id));

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});

it('skips weekly and monthly staging when the activity has no start_date_local', function (): void {
    $activity = Activity::factory()->create(['analyzed_at' => Carbon::now()]);
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => null,
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);

    fire($activity);

    expect(Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->exists())->toBeFalse()
        ->and(Analysis::query()->where('analysis_type', AnalysisType::MonthlyRecap)->exists())->toBeFalse();
    // The rest of the fan-out (activity group + daily set) is unaffected by a
    // missing start date, since $isToday null-safes to false rather than erroring.
    Bus::assertDispatched(AnalyzeActivityJob::class);
});

it('skips weekly recap staging when rebuildForwardFrom finds no in-window history', function (): void {
    // WeeklyAggregator's own rebuild correctness has its own dedicated suite
    // (WeeklyAggregatorTest); this only checks the listener's own branch —
    // a null return means no history to stage a recap against.
    $activity = analyzedActivity();
    $weekly = Mockery::mock(WeeklyAggregator::class);
    $weekly->shouldReceive('rebuildForwardFrom')->once()->andReturnNull();
    $listener = new DispatchPostRunAnalysis(app(AnalysisService::class), $weekly);

    $listener->handle(new ActivityIngested($activity->id));

    expect(Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->exists())->toBeFalse();
});
