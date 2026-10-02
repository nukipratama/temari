<?php

declare(strict_types=1);

use App\Events\ActivityIngested;
use App\Jobs\Run\RollWeeklySnapshotsForwardJob;
use App\Listeners\DispatchPostRunAnalysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\MaintainerAlerter;
use App\Services\Run\Ingest\SummaryIngest;
use App\Services\Run\Ingest\SyncOrchestrator;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Strava\ActivityFetcher;
use App\Services\Strava\StravaClient;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-05-11 09:00:00');
    Bus::fake()->except([RollWeeklySnapshotsForwardJob::class]);
});
afterEach(fn () => Carbon::setTestNow());

function debounceRun(User $user, string $startedAt, float $trimp): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startedAt),
        'distance' => 8000,
        'elapsed_time' => 2400,
        'trimp_edwards' => $trimp,
    ]);

    return $activity;
}

function debounceSnapshotRows(User $user): array
{
    return DB::table('weekly_snapshots')
        ->where('user_id', $user->id)
        ->orderBy('week_ending')
        ->get(['week_ending', 'distance_km', 'runs', 'weekly_trimp', 'atl_7d', 'ctl_42d', 'form', 'form_status'])
        ->map(fn (object $row): array => (array) $row)
        ->all();
}

function countSnapshotWrites(): Closure
{
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (str_starts_with($query->sql, 'insert into `weekly_snapshots`')) {
            $writes++;
        }
    });

    return function () use (&$writes): int {
        return $writes;
    };
}

it('rolls the weekly snapshots forward once for a drain of backfill runs, not once per run', function (): void {
    $user = User::factory()->create();
    $runs = collect(range(0, 5))->map(
        fn (int $i): Activity => debounceRun($user, Carbon::parse('2026-03-02 07:00')->addDays($i * 9)->toDateTimeString(), 60.0 + $i),
    );
    $writes = countSnapshotWrites();

    $runs->each(fn (Activity $run) => app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($run->id)));
    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    expect($writes())->toBe(1);

    $rolled = debounceSnapshotRows($user);
    app(WeeklyAggregator::class)->rebuildFor($user);

    expect($rolled)->not->toBeEmpty()
        ->and($rolled)->toBe(debounceSnapshotRows($user));
});

it('still rebuilds the week inline for a run landing today', function (): void {
    $user = User::factory()->create();
    debounceRun($user, '2026-04-20 07:00', 70.0);
    app(WeeklyAggregator::class)->rebuildFor($user);
    $today = debounceRun($user, '2026-05-11 07:00', 90.0);

    app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($today->id));

    $week = WeeklySnapshot::query()->where('user_id', $user->id)->where('week_ending', '2026-05-17')->firstOrFail();
    expect($week->runs)->toBe(1)
        ->and($week->weekly_trimp)->toBe(90.0)
        ->and($user->fresh()->weekly_snapshots_dirty_from)->toBeNull();
});

it('finishes the snapshots of a sync killed mid-rebuild on the next tick', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $client = Mockery::mock(StravaClient::class);
    $client->shouldReceive('rateLimitRemaining')->andReturn(['15min' => 200, 'daily' => 2000]);
    $fetcher = Mockery::mock(ActivityFetcher::class);
    $fetcher->shouldReceive('fetchNewSummaries')->andReturn([
        'summaries' => array_map(fn (int $id): array => [
            'id' => $id,
            'sport_type' => 'Run',
            'name' => 'Run',
            'start_date_local' => Carbon::parse('2026-03-03 06:00')->addDays(($id - 1) * 10)->format('Y-m-d\TH:i:s\Z'),
            'distance' => 5_000.0,
            'moving_time' => 1_800,
            'elapsed_time' => 1_800,
        ], [1, 2, 3]),
        'api_calls' => 1,
        'resume_before' => null,
    ]);
    $orchestrator = new SyncOrchestrator($fetcher, $client, app(SummaryIngest::class), app(WeeklyAggregator::class), app(MaintainerAlerter::class));
    $killed = true;
    DB::listen(function (QueryExecuted $query) use (&$killed): void {
        if ($killed && str_contains($query->sql, '`activity_details`.`trimp_edwards`, `activity_details`.`stream_summary`')) {
            throw new RuntimeException('worker killed');
        }
    });

    expect(fn () => $orchestrator->syncUser($user))->toThrow(RuntimeException::class, 'worker killed')
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->weekly_snapshots_dirty_from?->toDateString())->toBe('2026-03-08');

    $killed = false;
    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    expect(WeeklySnapshot::query()->where('user_id', $user->id)->sum('runs'))->toEqual(3)
        ->and(WeeklySnapshot::query()->where('user_id', $user->id)->min('week_ending'))->toBe('2026-03-08')
        ->and($user->fresh()->weekly_snapshots_dirty_from)->toBeNull();
});
