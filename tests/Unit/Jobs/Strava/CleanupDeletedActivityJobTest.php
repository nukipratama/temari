<?php

declare(strict_types=1);

use App\Jobs\Strava\CleanupDeletedActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(fn () => Bus::fake());

function makeRun(User $user, int $externalId, float $distance, Carbon\CarbonInterface $startedAt): Activity
{
    $activity = Activity::factory()->for($user)->create(['strava_external_id' => $externalId]);
    ActivityDetail::factory()->for($activity)->create([
        'distance' => $distance,
        'start_date_local' => $startedAt,
        'trimp_edwards' => 80,
    ]);

    return $activity;
}

it('deletes the run, recomputes the week, rebuilds PRs, and purges orphaned narration', function (): void {
    $user = User::factory()->create();
    $week = now()->startOfWeek();

    $doomed = makeRun($user, 7_001, 5_000, $week->copy()->addDay());
    $survivor = makeRun($user, 7_002, 8_000, $week->copy()->addDays(2));

    // Snapshots reflect both runs before the delete.
    app(WeeklyAggregator::class)->rebuildFor($user);
    $weekEnding = now()->endOfWeek(Carbon\CarbonInterface::SUNDAY)->startOfDay()->toDateString();
    expect(WeeklySnapshot::query()->where('user_id', $user->id)->where('week_ending', $weekEnding)->value('runs'))->toBe(2);

    // A PR pointing at the doomed run, and narration for both runs.
    PersonalRecord::factory()->for($user)->forActivity($doomed)->create(['category' => '5km']);
    foreach ([$doomed, $survivor] as $activity) {
        Analysis::factory()->create([
            'subject_type' => Activity::class,
            'subject_id' => $activity->id,
            'analysis_type' => AnalysisType::PostRunSpeech,
        ]);
    }

    // The doomed run's card carries a CardFlavor analysis (keyed by card id).
    $doomedCard = App\Models\RunCard::factory()->create(['activity_id' => $doomed->id]);
    Analysis::factory()->create([
        'subject_type' => App\Models\RunCard::class,
        'subject_id' => $doomedCard->id,
        'analysis_type' => AnalysisType::CardFlavor,
    ]);

    (new CleanupDeletedActivityJob($user->id, 7_001))->handle(
        app(WeeklyAggregator::class),
        app(\App\Services\Run\Metrics\PersonalRecords::class),
    );

    expect(Activity::query()->withStubs()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and(ActivityDetail::query()->where('activity_id', $doomed->id)->exists())->toBeFalse()
        ->and(Activity::query()->whereKey($survivor->id)->exists())->toBeTrue()
        // Week recomputed to only the survivor.
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->where('week_ending', $weekEnding)->value('runs'))->toBe(1)
        // Orphaned PR (pointed at the deleted run) is gone after the rebuild.
        ->and(PersonalRecord::query()->where('activity_id', $doomed->id)->exists())->toBeFalse()
        // Orphaned narration purged; the survivor's stays.
        ->and(Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $doomed->id)->exists())->toBeFalse()
        ->and(Analysis::query()->where('subject_type', Activity::class)->where('subject_id', $survivor->id)->exists())->toBeTrue()
        // The deleted card's CardFlavor analysis is purged too.
        ->and(Analysis::query()->where('subject_type', App\Models\RunCard::class)->where('subject_id', $doomedCard->id)->exists())->toBeFalse();
});

it('prunes a now-empty weekly snapshot when the deleted run was the last one', function (): void {
    $user = User::factory()->create();
    $sole = makeRun($user, 7_003, 5_000, now()->startOfWeek()->addDay());

    app(WeeklyAggregator::class)->rebuildFor($user);
    expect(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0);

    (new CleanupDeletedActivityJob($user->id, 7_003))->handle(
        app(WeeklyAggregator::class),
        app(\App\Services\Run\Metrics\PersonalRecords::class),
    );

    expect(Activity::query()->withStubs()->whereKey($sole->id)->exists())->toBeFalse()
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('no-ops when the activity is already gone', function (): void {
    $user = User::factory()->create();

    (new CleanupDeletedActivityJob($user->id, 999_999))->handle(
        app(WeeklyAggregator::class),
        app(\App\Services\Run\Metrics\PersonalRecords::class),
    );

    expect(true)->toBeTrue();
});
