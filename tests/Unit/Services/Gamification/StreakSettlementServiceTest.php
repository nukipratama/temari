<?php

declare(strict_types=1);

use App\Jobs\Gamification\SettleStreakWeeksJob;
use App\Models\StreakRestToken;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\StreakSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-06-01 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

function snapshotWeeks(User $user, Carbon $latest, int $count, int $runs = 2): void
{
    foreach (range(0, $count - 1) as $offset) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => $latest->copy()->subWeeks($offset)->toDateString(),
            'runs' => $runs,
        ]);
    }
}

it('rebuilds token outcomes chronologically and records a forgiven no-run week', function (): void {
    $user = User::factory()->create();
    snapshotWeeks($user, Carbon::parse('2026-05-24'), 4);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-31', 'runs' => 0]);

    $service = app(StreakSettlementService::class);
    expect($service->settle($user))->toBeTrue();

    $token = StreakRestToken::query()->where('user_id', $user->id)->sole();
    expect($token->spent_for_week_ending?->toDateString())->toBe('2026-05-31')
        ->and($user->fresh()->streak_settled_through?->toDateString())->toBe('2026-05-31')
        ->and($user->fresh()->streak_settlement_streak)->toBe(4);
});

it('processes an initial rebuild in bounded batches without replaying prior weeks', function (): void {
    $user = User::factory()->create();
    snapshotWeeks($user, Carbon::parse('2026-05-31'), 60);

    $service = app(StreakSettlementService::class);
    expect($service->settle($user))->toBeFalse()
        ->and($user->fresh()->streak_settled_through?->toDateString())->toBe('2026-04-05')
        ->and($user->fresh()->streak_settlement_streak)->toBe(52)
        ->and(StreakRestToken::query()->where('user_id', $user->id)->count())->toBe(2);

    expect($service->settle($user))->toBeTrue()
        ->and($user->fresh()->streak_settled_through?->toDateString())->toBe('2026-05-31')
        ->and($user->fresh()->streak_settlement_streak)->toBe(60)
        ->and(StreakRestToken::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('continues a cursor in bounded weekly batches', function (): void {
    $user = User::factory()->create([
        'streak_settled_through' => '2025-04-27',
        'streak_settlement_streak' => 0,
    ]);
    $latest = Carbon::parse('2026-05-31');
    snapshotWeeks($user, $latest, 58);
    $user->forceFill(['streak_settlement_dirty_from' => null])->saveQuietly();

    $service = app(StreakSettlementService::class);
    expect($service->settle($user))->toBeFalse()
        ->and($user->fresh()->streak_settled_through?->toDateString())->toBe('2026-04-26');

    expect($service->settle($user))->toBeTrue()
        ->and($user->fresh()->streak_settled_through?->toDateString())->toBe('2026-05-31')
        ->and($user->fresh()->streak_settlement_streak)->toBe(57);
});

it('rebuilds when a backdated weekly snapshot lowers the dirty cursor', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    snapshotWeeks($user, Carbon::parse('2026-05-31'), 4);

    $service = app(StreakSettlementService::class);
    expect($service->settle($user))->toBeTrue();

    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-05-03',
        'runs' => 1,
    ]);

    expect($user->fresh()->streak_settlement_dirty_from?->toDateString())->toBe('2026-05-03')
        ->and($service->allUsersSettled())->toBeFalse();

    expect($service->settle($user))->toBeTrue()
        ->and($user->fresh()->streak_settlement_dirty_from)->toBeNull()
        ->and(StreakRestToken::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(StreakRestToken::query()->where('user_id', $user->id)->sole()->earned_for_week_ending->toDateString())
        ->toBe('2026-05-24');
});

it('rebuilds when an already-settled weekly snapshot changes', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    snapshotWeeks($user, Carbon::parse('2026-05-31'), 5);

    $service = app(StreakSettlementService::class);
    expect($service->settle($user))->toBeTrue();

    $snapshot = WeeklySnapshot::query()
        ->where('user_id', $user->id)
        ->where('week_ending', '2026-05-31')
        ->firstOrFail();
    $snapshot->runs = 0;
    $snapshot->save();

    expect($user->fresh()->streak_settlement_dirty_from?->toDateString())->toBe('2026-05-31');

    expect($service->settle($user))->toBeTrue()
        ->and($user->fresh()->streak_settlement_dirty_from)->toBeNull()
        ->and(StreakRestToken::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(StreakRestToken::query()->where('user_id', $user->id)->sole()->spent_for_week_ending?->toDateString())
        ->toBe('2026-05-31');
});

it('refuses to truncate history beyond the safety bound', function (): void {
    $user = User::factory()->create();
    $earliest = Carbon::parse('2026-05-31')->subWeeks(StreakSettlementService::MAX_HISTORY_WEEKS);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => $earliest, 'runs' => 1]);

    expect(fn () => app(StreakSettlementService::class)->settle($user))
        ->toThrow(LogicException::class);
});

it('queues a continuation and does not mark the chain until the user catches up', function (): void {
    Bus::fake();
    $user = User::factory()->create([
        'streak_settled_through' => '2025-04-27',
        'streak_settlement_streak' => 0,
    ]);
    snapshotWeeks($user, Carbon::parse('2026-05-31'), 58);

    new SettleStreakWeeksJob($user->id)->handle(app(StreakSettlementService::class));

    Bus::assertDispatched(SettleStreakWeeksJob::class, fn (SettleStreakWeeksJob $job): bool => $job->userId === $user->id);
});

it('ignores demo history when deciding whether recaps may proceed', function (): void {
    $demo = User::factory()->demo()->create();
    snapshotWeeks($demo, Carbon::parse('2026-05-31'), 2);

    expect(app(StreakSettlementService::class)->allUsersSettled())->toBeTrue();
});
