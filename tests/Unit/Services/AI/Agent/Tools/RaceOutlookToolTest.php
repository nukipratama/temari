<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\PerformanceEvidence;
use App\Models\RaceGoal;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\AI\Agent\Tools\RaceOutlookTool;
use App\Services\Run\Metrics\DurationFormatter;
use App\Services\Run\Plan\RacePresenter;
use App\Services\Run\Plan\SupportedTimeHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-01 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

/** @return array<string, mixed> */
function raceOutlookFor(User $user, RaceGoal $race): array
{
    return new RaceOutlookTool($user, Carbon::today(), $race, app(RacePresenter::class), app(SupportedTimeHistory::class))->handle([]);
}

function raceOutlookTenK(User $user, int $seconds): void
{
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'activity_id' => Activity::factory()->for($user)->create()->id, 'kind' => 'test', 'distance_m' => 10_000, 'elapsed_time_sec' => $seconds,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
}

it('reads the race against the time recent running supports, as a gap size and a relation', function (): void {
    $user = User::factory()->create();
    raceOutlookTenK($user, 4200);
    $race = RaceGoal::factory()->for($user)->create(['distance_m' => 10_000, 'goal_time_sec' => 4_000, 'race_date' => '2026-10-31']);

    $outlook = raceOutlookFor($user, $race);

    expect($outlook)->toMatchArray([
        'distance_km' => 10.0,
        'race_date' => '2026-10-31',
        'days_to_race' => 30,
        'weeks_to_race' => 4,
        'target_time' => '1:06:40',
        'ambition' => 'ambitious',
        'dedicated_preparation' => true,
    ])
        ->and($outlook['supported_time'])->toStartWith('1:10:')
        ->and(data_get($outlook, 'gap.relation'))->toBe('target_faster')
        ->and(data_get($outlook, 'gap.pct'))->toBeGreaterThan(3.0)
        ->and($outlook['evidence_confidence'])->toBeString();
});

it('names a target slower than the supported time as target_slower, with no sign on the size', function (): void {
    $user = User::factory()->create();
    raceOutlookTenK($user, 4200);
    $race = RaceGoal::factory()->for($user)->create(['distance_m' => 10_000, 'goal_time_sec' => 4_500]);

    $gap = arrayOf(raceOutlookFor($user, $race)['gap']);

    expect($gap['relation'])->toBe('target_slower')
        ->and($gap['pct'])->toBeGreaterThan(0.0);
});

it('reads which way the supported time moved across the season', function (array $times, string $relation): void {
    /** @var non-empty-list<int> $times */
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create();
    foreach ($times as $offset => $seconds) {
        TrendDailySnapshot::factory()->for($user)->create([
            'snapshot_date' => Carbon::today()->subDays(10 - $offset)->toDateString(),
            'race_goal_id' => $race->id,
            'supported_time_sec' => $seconds,
            'supported_source_distance_m' => 5000,
            'supported_source_date' => '2026-09-01',
        ]);
    }

    expect(raceOutlookFor($user, $race)['support_trend'])->toBe([
        'since' => '2026-09-21',
        'from' => DurationFormatter::hms($times[0]),
        'to' => DurationFormatter::hms(array_last($times)),
        'relation' => $relation,
    ]);
})->with([
    'faster' => [[3_300, 3_250, 3_200], 'faster'],
    'slower' => [[3_200, 3_300], 'slower'],
    'flat' => [[3_200, 3_250, 3_200], 'flat'],
]);

it('reads no supported time, gap or trend without evidence', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create();

    expect(raceOutlookFor($user, $race))->toMatchArray([
        'supported_time' => null,
        'ambition' => 'unknown',
        'gap' => null,
        'support_trend' => null,
    ]);
});

it('marks a distance the plan does not prepare for', function (): void {
    $user = User::factory()->create();
    raceOutlookTenK($user, 4200);
    $race = RaceGoal::factory()->for($user)->create(['distance_m' => 50_000, 'goal_time_sec' => 18_000]);

    expect(raceOutlookFor($user, $race))->toMatchArray([
        'distance_km' => 50.0,
        'supported_time' => null,
        'dedicated_preparation' => false,
    ]);
});

it('never counts a race day already past as negative days', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => '2026-09-29']);

    expect(raceOutlookFor($user, $race))->toMatchArray(['days_to_race' => 0, 'weeks_to_race' => 0]);
});
