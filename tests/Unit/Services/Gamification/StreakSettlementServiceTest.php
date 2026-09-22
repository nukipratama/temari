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
        ->and($user->fresh()->streak_settled_through?->toDateString())->toBe('2026-05-31');
});

it('continues a cursor in bounded weekly batches', function (): void {
    $user = User::factory()->create(['streak_settled_through' => '2025-04-27']);
    $latest = Carbon::parse('2026-05-31');
    snapshotWeeks($user, $latest, 58);

    $service = app(StreakSettlementService::class);
    expect($service->settle($user))->toBeFalse()
        ->and($user->fresh()->streak_settled_through?->toDateString())->toBe('2026-04-26');

    expect($service->settle($user))->toBeTrue()
        ->and($user->fresh()->streak_settled_through?->toDateString())->toBe('2026-05-31');
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
    $user = User::factory()->create(['streak_settled_through' => '2025-04-27']);
    snapshotWeeks($user, Carbon::parse('2026-05-31'), 58);

    new SettleStreakWeeksJob($user->id)->handle(app(StreakSettlementService::class));

    Bus::assertDispatched(SettleStreakWeeksJob::class, fn (SettleStreakWeeksJob $job): bool => $job->userId === $user->id);
});
