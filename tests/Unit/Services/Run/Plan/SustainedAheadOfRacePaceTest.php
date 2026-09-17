<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Models\PlanAdaptation;
use App\Models\User;
use App\Services\Run\Plan\SustainedAheadOfRacePace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function adaptationWeek(User $user, string $weekStart, AdaptationReason $reason): void
{
    PlanAdaptation::factory()->for($user)->create([
        'week_start' => $weekStart,
        'reason' => $reason,
    ]);
}

it('reads no signal at all when nothing has been evaluated yet', function (): void {
    $user = User::factory()->create();

    expect(app(SustainedAheadOfRacePace::class)->forUser($user->id, Carbon::parse('2026-09-14')))->toBeFalse();
});

it('does not call a single ahead week sustained', function (): void {
    $user = User::factory()->create();
    adaptationWeek($user, '2026-09-14', AdaptationReason::AheadOfRacePace);

    expect(app(SustainedAheadOfRacePace::class)->forUser($user->id, Carbon::parse('2026-09-14')))->toBeFalse();
});

it('calls it sustained once two consecutive evaluated weeks both land ahead', function (): void {
    $user = User::factory()->create();
    adaptationWeek($user, '2026-09-07', AdaptationReason::AheadOfRacePace);
    adaptationWeek($user, '2026-09-14', AdaptationReason::AheadOfRacePace);

    expect(app(SustainedAheadOfRacePace::class)->forUser($user->id, Carbon::parse('2026-09-14')))->toBeTrue();
});

it('breaks the streak the moment one of the two weeks lands on a different reason', function (): void {
    $user = User::factory()->create();
    adaptationWeek($user, '2026-09-07', AdaptationReason::Steady);
    adaptationWeek($user, '2026-09-14', AdaptationReason::AheadOfRacePace);

    expect(app(SustainedAheadOfRacePace::class)->forUser($user->id, Carbon::parse('2026-09-14')))->toBeFalse();
});

it('reads a gap where a week was never evaluated as not sustained, rather than guessing across it', function (): void {
    $user = User::factory()->create();
    // Two weeks back and this week are ahead, but the week between was never
    // regenerated -- no row at all, not even a Steady one.
    adaptationWeek($user, '2026-08-31', AdaptationReason::AheadOfRacePace);
    adaptationWeek($user, '2026-09-14', AdaptationReason::AheadOfRacePace);

    expect(app(SustainedAheadOfRacePace::class)->forUser($user->id, Carbon::parse('2026-09-14')))->toBeFalse();
});

it('never lets one athlete\'s streak answer for another', function (): void {
    $ahead = User::factory()->create();
    $other = User::factory()->create();
    adaptationWeek($ahead, '2026-09-07', AdaptationReason::AheadOfRacePace);
    adaptationWeek($ahead, '2026-09-14', AdaptationReason::AheadOfRacePace);
    adaptationWeek($other, '2026-09-07', AdaptationReason::AheadOfRacePace);
    adaptationWeek($other, '2026-09-14', AdaptationReason::Steady);

    expect(app(SustainedAheadOfRacePace::class)->forUser($other->id, Carbon::parse('2026-09-14')))->toBeFalse();
});
