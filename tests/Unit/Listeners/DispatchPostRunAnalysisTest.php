<?php

declare(strict_types=1);

use App\Events\ActivityIngested;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Jobs\AI\AnalyzeDailyGreetingJob;
use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Listeners\DispatchPostRunAnalysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    $this->listener = app(DispatchPostRunAnalysis::class);
});

/** Seed an already-ingested activity (analyzed_at set + detail) the listener can fan out from. */
function analyzedActivity(string $startDate = '2026-05-10 06:30:00'): Activity
{
    $activity = Activity::factory()->create(['analyzed_at' => Carbon::now()]);
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

it('fans out activity + briefing + greeting + weekly analyses', function (): void {
    $activity = analyzedActivity();

    fire($activity);

    Bus::assertDispatched(AnalyzeActivityJob::class);
    Bus::assertDispatched(AnalyzeBriefingJob::class);
    Bus::assertDispatched(AnalyzeDailyGreetingJob::class);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
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

it('no-ops when the activity was deleted before the queued listener ran', function (): void {
    $activity = analyzedActivity();
    $id = $activity->id;
    $activity->detail()->delete();
    $activity->delete();

    app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($id));

    Bus::assertNotDispatched(AnalyzeActivityJob::class);
});
