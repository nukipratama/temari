<?php

declare(strict_types=1);

use App\Actions\Gamification\SettleStreakRestTokensAction;
use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\SeasonStreakSummaryBuilder;
use App\Services\Run\Plan\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->builder = app(SeasonStreakSummaryBuilder::class);
});
afterEach(fn () => Carbon::setTestNow());

it('returns null season payload when there is no season, without creating one', function (): void {
    $user = User::factory()->create();

    $payload = $this->builder->seasonPayload($user, null, Carbon::today());

    expect($payload)->toBeNull();
});

it('builds the same season payload PlanController used to build inline, given an ensured season', function (): void {
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    $payload = $this->builder->seasonPayload($user, $season, Carbon::today());

    expect($payload)
        ->toHaveKeys(['starts_at', 'ends_at', 'week_index', 'total_weeks', 'is_race_oriented', 'tiers_kept_from_past_seasons', 'goals'])
        ->and($payload['week_index'])->toBe(1)
        ->and($payload['is_race_oriented'])->toBeFalse()
        ->and($payload['goals'])->toHaveCount(5);
});

it('counts only an earlier season\'s track tiers as kept, never the live season\'s', function (): void {
    $user = User::factory()->create();
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    UserUnlock::query()->insert([
        ['user_id' => $user->id, 'unlock_key' => 'season.999.track_1', 'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $user->id, 'unlock_key' => "season.{$season->id}.track_1", 'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $payload = $this->builder->seasonPayload($user, $season, Carbon::today());

    expect($payload['tiers_kept_from_past_seasons'])->toBe(1);
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
            'rest_weeks_cap' => SettleStreakRestTokensAction::MAX_HELD,
            'weeks_to_next_rest_week' => 3,
            'ran_this_week' => true,
            'week_ends_on' => '2026-08-16',
            'last_forgiven_week' => null,
        ]);
});

it('stops forecasting the next rest week once the held ones are capped, and names the last forgiven week', function (): void {
    $user = User::factory()->create();
    foreach (range(1, SettleStreakRestTokensAction::MAX_HELD) as $offset) {
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

    expect($payload['rest_weeks_held'])->toBe(SettleStreakRestTokensAction::MAX_HELD)
        ->and($payload['weeks_to_next_rest_week'])->toBeNull()
        ->and($payload['last_forgiven_week'])->toBe('2026-07-05');
});
