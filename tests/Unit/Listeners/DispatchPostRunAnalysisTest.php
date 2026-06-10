<?php

declare(strict_types=1);

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

it('does not re-bill the daily briefing set on the second run of the day', function (): void {
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

    // The new run still gets its own narration; the daily set is debounced.
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

it('no-ops when the activity was deleted before the queued listener ran', function (): void {
    $activity = analyzedActivity();
    $id = $activity->id;
    $activity->detail()->delete();
    $activity->delete();

    app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($id));

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});
