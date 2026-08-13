<?php

declare(strict_types=1);

use App\Actions\Gamification\SettleStreakRestTokensAction;
use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

const CLOSED_WEEK = '2026-05-31';

beforeEach(function (): void {
    // The Monday right after CLOSED_WEEK, which is when the command runs.
    Carbon::setTestNow('2026-06-01 00:00:00');
    $this->settle = app(SettleStreakRestTokensAction::class);
});
afterEach(fn () => Carbon::setTestNow());

function runWeeks(User $user, int $count): void
{
    $week = Carbon::parse(CLOSED_WEEK);
    for ($i = 0; $i < $count; $i++) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => $week->copy()->subDays(7 * $i)->toDateString(),
            'runs' => 2,
        ]);
    }
}

it('mints a token when the closed week completes a training cycle', function (): void {
    $user = User::factory()->create();
    runWeeks($user, SettleStreakRestTokensAction::ACCRUAL_EVERY_WEEKS);

    expect(($this->settle)($user, Carbon::parse(CLOSED_WEEK)))->toBe('accrued')
        ->and(StreakRestToken::unspentCountForUser($user->id))->toBe(1);
});

it('mints nothing partway through a cycle', function (): void {
    $user = User::factory()->create();
    runWeeks($user, SettleStreakRestTokensAction::ACCRUAL_EVERY_WEEKS - 1);

    expect(($this->settle)($user, Carbon::parse(CLOSED_WEEK)))->toBe('none')
        ->and(StreakRestToken::unspentCountForUser($user->id))->toBe(0);
});

it('stops minting at the hold cap', function (): void {
    $user = User::factory()->create();
    runWeeks($user, SettleStreakRestTokensAction::ACCRUAL_EVERY_WEEKS * 3);
    foreach (['2026-04-05', '2026-04-12'] as $earned) {
        StreakRestToken::factory()->for($user)->create(['earned_for_week_ending' => $earned]);
    }

    expect(($this->settle)($user, Carbon::parse(CLOSED_WEEK)))->toBe('none')
        ->and(StreakRestToken::unspentCountForUser($user->id))->toBe(SettleStreakRestTokensAction::MAX_HELD);
});

it('mints at most one token for the same closed week', function (): void {
    $user = User::factory()->create();
    runWeeks($user, SettleStreakRestTokensAction::ACCRUAL_EVERY_WEEKS);

    ($this->settle)($user, Carbon::parse(CLOSED_WEEK));
    ($this->settle)($user, Carbon::parse(CLOSED_WEEK));

    expect(StreakRestToken::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('spends a token to forgive a runless week when a streak is live', function (): void {
    $user = User::factory()->create();
    // Runs up to the week before the closed one, then nothing.
    $week = Carbon::parse(CLOSED_WEEK)->subDays(7);
    for ($i = 0; $i < 3; $i++) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => $week->copy()->subDays(7 * $i)->toDateString(),
            'runs' => 2,
        ]);
    }
    StreakRestToken::factory()->for($user)->create(['earned_for_week_ending' => '2026-05-10']);

    expect(WeeklySnapshot::consecutiveWeekStreak($user->id))->toBe(0);

    expect(($this->settle)($user, Carbon::parse(CLOSED_WEEK)))->toBe('spent')
        ->and(StreakRestToken::unspentCountForUser($user->id))->toBe(0)
        ->and(WeeklySnapshot::consecutiveWeekStreak($user->id))->toBe(3);
});

it('burns no token when there is no streak to save', function (): void {
    $user = User::factory()->create();
    StreakRestToken::factory()->for($user)->create(['earned_for_week_ending' => '2026-05-10']);

    expect(($this->settle)($user, Carbon::parse(CLOSED_WEEK)))->toBe('none')
        ->and(StreakRestToken::unspentCountForUser($user->id))->toBe(1);
});

it('does nothing for a runless week when the user holds no token', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::parse(CLOSED_WEEK)->subDays(7)->toDateString(),
        'runs' => 2,
    ]);

    expect(($this->settle)($user, Carbon::parse(CLOSED_WEEK)))->toBe('none')
        ->and(WeeklySnapshot::consecutiveWeekStreak($user->id))->toBe(0);
});
