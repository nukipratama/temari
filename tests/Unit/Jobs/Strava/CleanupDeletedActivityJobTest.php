<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use App\Models\RunCard;
use App\Services\Run\Metrics\PersonalRecords;
use App\Jobs\Strava\CleanupDeletedActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Strava\StravaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Bus::fake());

function makeCleanupRun(User $user, int $externalId, float $distance, CarbonInterface $startedAt): Activity
{
    $activity = Activity::factory()->for($user)->create(['strava_external_id' => $externalId]);
    ActivityDetail::factory()->for($activity)->create([
        'distance' => $distance,
        'start_date_local' => $startedAt,
        'trimp_edwards' => 80,
    ]);

    return $activity;
}

/**
 * The cleanup job now verifies the deletion against Strava (a 404) before acting.
 * Give the user a live connection and fake the activity endpoint to 404 so the
 * verification passes.
 */
function fakeStravaConfirms404(User $user, int $externalId): void
{
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => $externalId + 1_000_000]);
    Http::fake(["strava.com/api/v3/activities/{$externalId}" => Http::response(['error' => 'Record Not Found'], 404)]);
}

it('deletes the run, recomputes the week, rebuilds PRs, and purges orphaned narration', function (): void {
    $user = User::factory()->create();
    $week = now()->startOfWeek();

    $doomed = makeCleanupRun($user, 7_001, 5_000, $week->copy()->addDay());
    $survivor = makeCleanupRun($user, 7_002, 8_000, $week->copy()->addDays(2));
    fakeStravaConfirms404($user, 7_001);

    // Snapshots reflect both runs before the delete.
    app(WeeklyAggregator::class)->rebuildFor($user);
    $weekEnding = now()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()->toDateString();
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
    $doomedCard = RunCard::factory()->create(['activity_id' => $doomed->id]);
    Analysis::factory()->create([
        'subject_type' => RunCard::class,
        'subject_id' => $doomedCard->id,
        'analysis_type' => AnalysisType::CardFlavor,
    ]);

    new CleanupDeletedActivityJob($user->id, 7_001)->handle(
        app(WeeklyAggregator::class),
        app(PersonalRecords::class),
        app(StravaClient::class),
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
        ->and(Analysis::query()->where('subject_type', RunCard::class)->where('subject_id', $doomedCard->id)->exists())->toBeFalse();
});

it('prunes a now-empty weekly snapshot when the deleted run was the last one', function (): void {
    // WeeklyAggregator/PersonalRecords are mocked here — the job's own contract
    // under test is "when rebuildForwardFrom reports an empty lookback (null),
    // prune the now-stale forward snapshots"; the aggregator's own rebuild
    // correctness has its own dedicated suite (WeeklyAggregatorTest).
    $user = User::factory()->create();
    $sole = makeCleanupRun($user, 7_003, 5_000, now()->startOfWeek()->addDay());
    $weekEnding = now()->endOfWeek(CarbonInterface::SUNDAY)->startOfDay()->toDateString();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => $weekEnding, 'runs' => 1]);
    fakeStravaConfirms404($user, 7_003);

    $weekly = Mockery::mock(WeeklyAggregator::class);
    $weekly->shouldReceive('rebuildForwardFrom')->once()->andReturnNull();
    $personalRecords = Mockery::mock(PersonalRecords::class);
    $personalRecords->shouldReceive('rebuildForUser')->once();

    new CleanupDeletedActivityJob($user->id, 7_003)->handle($weekly, $personalRecords, app(StravaClient::class));

    expect(Activity::query()->withStubs()->whereKey($sole->id)->exists())->toBeFalse()
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('no-ops when the activity is already gone', function (): void {
    $user = User::factory()->create();

    new CleanupDeletedActivityJob($user->id, 999_999)->handle(
        app(WeeklyAggregator::class),
        app(PersonalRecords::class),
        app(StravaClient::class),
    );

    expect(true)->toBeTrue();
});

it('does NOT delete when Strava still returns the activity (forged delete event)', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['strava_athlete_id' => 42]);
    $activity = makeCleanupRun($user, 7_010, 5_000, now()->startOfWeek()->addDay());

    // Strava confirms the activity still exists: the delete webhook was forged.
    Http::fake(['strava.com/api/v3/activities/7010' => Http::response(['id' => 7_010], 200)]);

    new CleanupDeletedActivityJob($user->id, 7_010)->handle(
        app(WeeklyAggregator::class),
        app(PersonalRecords::class),
        app(StravaClient::class),
    );

    expect(Activity::query()->whereKey($activity->id)->exists())->toBeTrue()
        ->and(ActivityDetail::query()->where('activity_id', $activity->id)->exists())->toBeTrue();
});

it('does NOT delete when there is no live connection to verify against', function (): void {
    $user = User::factory()->create();
    $activity = makeCleanupRun($user, 7_011, 5_000, now()->startOfWeek()->addDay());
    Http::fake();

    new CleanupDeletedActivityJob($user->id, 7_011)->handle(
        app(WeeklyAggregator::class),
        app(PersonalRecords::class),
        app(StravaClient::class),
    );

    expect(Activity::query()->whereKey($activity->id)->exists())->toBeTrue();
    Http::assertNothingSent();
});
