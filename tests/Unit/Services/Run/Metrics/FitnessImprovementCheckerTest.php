<?php

declare(strict_types=1);

use App\Models\InboxNotification;
use App\Models\PerformanceEvidence;
use App\Models\User;
use App\Notifications\FitnessImprovedNotification;
use App\Services\Run\Metrics\FitnessImprovementChecker;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-05 10:00:00'));

afterEach(fn () => Carbon::setTestNow());

function checkerAthlete(?float $lastNotifiedVdot): User
{
    $user = User::factory()->create(['last_notified_vdot' => $lastNotifiedVdot, 'last_notified_race_m' => 10_000]);
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'kind' => 'race', 'distance_m' => 10_000, 'elapsed_time_sec' => 3_500,
        'performed_on' => '2026-09-27', 'confirmed_at' => '2026-09-27 12:00:00',
    ]);

    return $user;
}

function checkerVdot(): float
{
    return round(nonNull(app(VdotEstimator::class)->vdotFromTimeAndDistance(3_500, 10_000)), 1);
}

function checkFitness(User $user): ?string
{
    return app(FitnessImprovementChecker::class)->check($user, Carbon::today(), Carbon::parse('2026-10-05 09:30:00'));
}

it('seeds a first baseline and stamps the check', function (): void {
    $user = checkerAthlete(null);

    expect(checkFitness($user))->toBe('seeded')
        ->and($user->refresh()->last_notified_vdot)->toBe(checkerVdot())
        ->and(nonNull($user->fitness_checked_at)->toDateTimeString())->toBe('2026-10-05 09:30:00');
});

it('notes a rise past the threshold', function (): void {
    Notification::fake();
    $user = checkerAthlete(checkerVdot() - FitnessImprovementChecker::IMPROVEMENT_THRESHOLD_VDOT);

    expect(checkFitness($user))->toBe('sent');
    Notification::assertSentTo($user, FitnessImprovedNotification::class);
});

it('holds a rise inside the note interval without stamping the check', function (): void {
    Notification::fake();
    $user = checkerAthlete(checkerVdot() - 1.0);
    InboxNotification::factory()->for($user)->create([
        'kind' => 'fitness_improved', 'dedupe_key' => 'fitness_improved:2026-09-30', 'created_at' => '2026-09-30 10:00:05',
    ]);

    expect(checkFitness($user))->toBe('held')
        ->and($user->refresh()->fitness_checked_at)->toBeNull();
    Notification::assertNothingSent();
});
