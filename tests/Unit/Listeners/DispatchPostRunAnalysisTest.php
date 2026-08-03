<?php

declare(strict_types=1);

use App\Models\User;
use App\Events\ActivityIngested;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeAkuProfileVoiceJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzeCardFlavorJob;
use App\Jobs\AI\AnalyzePrContextJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Listeners\DispatchPostRunAnalysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Actions\AI\StaggerBackfillAction;
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

it('requests card flavor for the run card the ingest minted', function (): void {
    $activity = analyzedActivity();
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);

    Bus::assertDispatched(AnalyzeCardFlavorJob::class);
    expect(Analysis::query()
        ->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)
        ->exists())->toBeTrue();
});

it('re-narrates card flavor on a re-ingest (invalidate:true) without minting a second row', function (): void {
    $activity = analyzedActivity();
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);

    fire($activity);
    $row = Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail();
    app(AnalysisService::class)->markDone($row, 'kartu pertama');

    fire($activity);

    expect(Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->count())->toBe(1)
        ->and($row->fresh()->status)->not->toBe(AnalysisStatus::Done);
});

it('requests pr_context for the records this run holds, invalidate:false so a backfill never re-bills', function (): void {
    $activity = analyzedActivity();
    $held = PersonalRecord::factory()->for($activity->user)->create([
        'category' => '5km',
        'activity_id' => $activity->id,
    ]);
    // Held by an older run: this ingest did not beat it, so it is not re-requested.
    $other = PersonalRecord::factory()->for($activity->user)->create(['category' => '10km']);

    fire($activity);
    $row = Analysis::query()->forSubject(PersonalRecord::class, $held->id, AnalysisType::PrContext)->firstOrFail();
    app(AnalysisService::class)->markDone($row, 'rekor pertama');

    fire($activity);

    expect(Analysis::query()->forSubject(PersonalRecord::class, $held->id, AnalysisType::PrContext)->count())->toBe(1)
        // Already Done: the second fan-out is a no-op, never a second bill.
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done)
        ->and(Analysis::query()->forSubject(PersonalRecord::class, $other->id, AnalysisType::PrContext)->exists())->toBeFalse();
});

it('dispatches AkuProfileVoice on first ingest, keyed by the current ISO week', function (): void {
    Carbon::setTestNow('2026-05-19 12:00:00');
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertDispatched(
        AnalyzeAkuProfileVoiceJob::class,
        fn (AnalyzeAkuProfileVoiceJob $job): bool => Analysis::query()->whereKey($job->analysisId)->value('discriminator') === AnalysisType::currentIsoWeek(),
    );
    Carbon::setTestNow();
});

it('does not re-bill a Done AkuProfileVoice row on re-ingest (invalidate:false)', function (): void {
    $activity = analyzedActivity();
    fire($activity);

    $row = Analysis::query()
        ->where('subject_type', AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $activity->user_id)
        ->where('analysis_type', AnalysisType::AkuProfileVoice)
        ->firstOrFail();
    app(AnalysisService::class)->markDone($row, 'kata Temari pertama');

    fire($activity);

    expect(Analysis::query()
        ->where('subject_type', AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE)
        ->where('subject_id', $activity->user_id)
        ->where('analysis_type', AnalysisType::AkuProfileVoice)
        ->count())->toBe(1)
        ->and($row->fresh()->status)->toBe(AnalysisStatus::Done);
});

it('fans out activity + briefing + mascot voice analyses', function (): void {
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
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
        AnalyzeBriefingMascotVoiceJob::class,
        fn (AnalyzeBriefingMascotVoiceJob $job): bool => Analysis::query()->whereKey($job->analysisId)->value('discriminator') === '2026-05-19',
    );
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Carbon::setTestNow();
});

it('refreshes the daily briefing set on the second run of the day', function (): void {
    Carbon::setTestNow('2026-05-19 06:00:00');
    $first = analyzedActivity('2026-05-19 05:30:00');
    fire($first);

    // The morning's briefing set finishes generating (rows flip to Done).
    Analysis::query()
        ->whereIn('analysis_type', [
            AnalysisType::BriefingMascotVoice->value,
            AnalysisType::BriefingMascotVoice->value,
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
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Carbon::setTestNow();
});

it('does not re-bill the daily set when backfilling a previous-day run', function (): void {
    Carbon::setTestNow('2026-05-19 09:00:00');
    $today = analyzedActivity('2026-05-19 06:00:00');
    fire($today);

    // Today's daily set finishes generating (rows flip to Done).
    Analysis::query()
        ->whereIn('analysis_type', [
            AnalysisType::BriefingMascotVoice->value,
            AnalysisType::BriefingMascotVoice->value,
        ])
        ->get()
        ->each(fn (Analysis $row) => app(AnalysisService::class)->markDone($row, 'sudah jadi'));

    Bus::fake();
    // Backfilling a run from two days ago must not re-bill today's daily set.
    $backfill = analyzedActivity('2026-05-17 06:00:00', $today->user_id);
    fire($backfill);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
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

it('staggers card_flavor and pr_context by the same backfill delay as the activity group', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    config()->set('ai.backfill_stagger_seconds', 100);

    // First backfilled ingest reserves the immediate (0-delay) slot for this user.
    $first = analyzedActivity('2026-05-01 06:00:00');
    fire($first);

    Bus::fake();
    // Second backfilled ingest for the same user gets staggered behind the first.
    $activity = analyzedActivity('2026-05-02 06:00:00', $first->user_id);
    RunCard::factory()->create(['activity_id' => $activity->id]);
    PersonalRecord::factory()->for($activity->user)->create([
        'category' => '5km',
        'activity_id' => $activity->id,
    ]);

    fire($activity);

    Bus::assertDispatched(AnalyzeCardFlavorJob::class, fn (AnalyzeCardFlavorJob $job): bool => $job->delay === 100);
    Bus::assertDispatched(AnalyzePrContextJob::class, fn (AnalyzePrContextJob $job): bool => $job->delay === 100);
    Carbon::setTestNow();
});

it('staggers AkuProfileVoice by the backfill delay on the ingest that first originates its row', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    config()->set('ai.backfill_stagger_seconds', 100);
    $activity = analyzedActivity('2026-05-01 06:00:00');

    // Reserve the immediate (0-delay) slot ahead of this ingest, so its own
    // dispatch — including the AkuProfileVoice row it originates — is staggered.
    app(StaggerBackfillAction::class)($activity->user_id);

    fire($activity);

    Bus::assertDispatched(AnalyzeAkuProfileVoiceJob::class, fn (AnalyzeAkuProfileVoiceJob $job): bool => $job->delay === 100);
    Carbon::setTestNow();
});

it('fills an activity older than the backfill depth cap rule-based (group + card + pr context), no real dispatch', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    config()->set('ai.backfill_max_age_days', 365);
    // Well over 365 days before 2026-06-10.
    $activity = analyzedActivity('2025-01-01 06:00:00');
    $card = RunCard::factory()->create(['activity_id' => $activity->id]);
    $pr = PersonalRecord::factory()->for($activity->user)->create([
        'category' => '5km',
        'activity_id' => $activity->id,
    ]);

    fire($activity);

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
    Bus::assertNotDispatched(AnalyzeCardFlavorJob::class);

    $groupRows = Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $activity->id)->get();
    expect($groupRows)->toHaveCount(4)
        ->and($groupRows->every(fn (Analysis $row): bool => $row->status === AnalysisStatus::Done))->toBeTrue();

    $cardRow = Analysis::query()->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)->firstOrFail();
    expect($cardRow->status)->toBe(AnalysisStatus::Done);

    $prRow = Analysis::query()->forSubject(PersonalRecord::class, $pr->id, AnalysisType::PrContext)->firstOrFail();
    expect($prRow->status)->toBe(AnalysisStatus::Done);

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

it('a live (non-backfill) run joins the chain instead of jumping ahead when an older link is unresolved', function (): void {
    Carbon::setTestNow('2026-06-10 09:00:00');
    // An older run already staged Pending (e.g. an in-progress backfill).
    $older = analyzedActivity('2026-05-10 06:00:00');
    app(AnalysisService::class)->requestActivityGroupDeferred($older);

    Bus::fake();
    // A live (fresh, non-backfill) run comes in while that older link is still unresolved.
    $fresh = analyzedActivity('2026-06-10 06:00:00', $older->user_id);
    fire($fresh);

    // Staged (joins the chain), not dispatched directly.
    $freshRow = Analysis::query()
        ->where('subject_type', Activity::class)
        ->where('subject_id', $fresh->id)
        ->where('analysis_type', AnalysisType::PostRunSpeech)
        ->firstOrFail();
    expect($freshRow->status)->toBe(AnalysisStatus::Pending);

    // The chain re-kicks the older, still-earliest link — not the fresh run.
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $older->id,
    );
    Bus::assertNotDispatched(
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
    $listener = new DispatchPostRunAnalysis(app(AnalysisService::class), $weekly, app(StaggerBackfillAction::class));

    $listener->handle(new ActivityIngested($activity->id));

    expect(Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->exists())->toBeFalse();
});
