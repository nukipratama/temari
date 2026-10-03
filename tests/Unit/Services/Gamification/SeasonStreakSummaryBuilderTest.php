<?php

declare(strict_types=1);

use App\Models\RaceGoal;
use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Gamification\StreakSettlementService;
use App\Services\Run\Plan\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->builder = app(SeasonStreakSummaryBuilder::class);
});
afterEach(fn () => Carbon::setTestNow());

it('returns null season payloads when there is no season', function (): void {
    $user = User::factory()->create();

    expect($this->builder->seasonPayload(null, Carbon::today()))->toBeNull()
        ->and($this->builder->profileSeasonPayload($user, null, Carbon::today()))->toBeNull();
});

it('builds the Plan season frame without goals or record, given an ensured season', function (): void {
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    $payload = $this->builder->seasonPayload($season, Carbon::today());

    expect($payload)
        ->toHaveKeys(['starts_at', 'ends_at', 'week_index', 'total_weeks', 'is_race_oriented', 'block_opens_on'])
        ->not->toHaveKeys(['goals', 'record'])
        ->and($payload['week_index'])->toBe(1)
        ->and($payload['is_race_oriented'])->toBeFalse()
        ->and($payload['block_opens_on'])->toBeNull();
});

it('builds the Profile season with its resolved goals and none of the Plan-only fields', function (): void {
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    $payload = $this->builder->profileSeasonPayload($user, $season, Carbon::today());

    expect($payload)->not->toBeNull()
        ->and(array_keys($payload))->toBe(['starts_at', 'ends_at', 'week_index', 'total_weeks', 'goals'])
        ->and($payload['week_index'])->toBe(1)
        ->and($payload['goals'])->toHaveCount(5)
        ->and($payload['goals'][0])->toHaveKeys(['id', 'title', 'current', 'target', 'unit', 'is_completed']);
});

it('counts season weeks as the plan\'s Monday weeks when the season opens mid-week', function (): void {
    Carbon::setTestNow('2026-09-05 08:00:00');
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());
    expect($season->starts_at->toDateString())->toBe('2026-09-05');

    $payload = $this->builder->seasonPayload($season, Carbon::parse('2026-09-24'));

    expect($payload['week_index'])->toBe(4);
});

it('carries the day a race season\'s block opens', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => '2027-03-13', 'distance_m' => 42_195]);
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    expect($this->builder->seasonPayload($season, Carbon::today())['block_opens_on'])->toBe('2026-10-26');
});

it('has no block to open for a race beyond the marathon', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => '2027-03-13', 'distance_m' => 80_000]);
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    expect($this->builder->seasonPayload($season, Carbon::today())['block_opens_on'])->toBeNull();
});

it('reports the weekly streak with its open week and no rest weeks held', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->create([
        'user_id' => $user->id,
        'week_ending' => '2026-08-16',
        'runs' => 3,
    ]);

    $payload = $this->builder->streakPayload($user, Carbon::today());

    expect($payload)
        ->toBe([
            'weeks' => 1,
            'rest_weeks_held' => 0,
            'rest_weeks_cap' => StreakSettlementService::MAX_HELD,
            'weeks_to_next_rest_week' => 3,
            'ran_this_week' => true,
            'week_ends_on' => '2026-08-16',
            'last_forgiven_week' => null,
        ]);
});

it('stops forecasting the next rest week once the held ones are capped, and names the last forgiven week', function (): void {
    $user = User::factory()->create();
    foreach (range(1, StreakSettlementService::MAX_HELD) as $offset) {
        StreakRestToken::factory()->create([
            'user_id' => $user->id,
            'earned_for_week_ending' => Carbon::parse('2026-08-09')->subWeeks($offset)->toDateString(),
        ]);
    }
    StreakRestToken::factory()->create([
        'user_id' => $user->id,
        'earned_for_week_ending' => '2026-05-31',
        'spent_for_week_ending' => '2026-07-05',
    ]);

    $payload = $this->builder->streakPayload($user, Carbon::today());

    expect($payload['rest_weeks_held'])->toBe(StreakSettlementService::MAX_HELD)
        ->and($payload['weeks_to_next_rest_week'])->toBeNull()
        ->and($payload['last_forgiven_week'])->toBe('2026-07-05');
});
