<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Plan\WeekSessionTypesBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function seedMixedWeek(User $user, Carbon $weekStart): void
{
    $types = [
        SessionType::Easy,
        SessionType::Rest,
        SessionType::Tempo,
        SessionType::Easy,
        SessionType::Rest,
        SessionType::Interval,
        SessionType::Long,
    ];

    foreach ($types as $offset => $type) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->addDays($offset),
            'phase' => PlanPhase::Base,
            'session_type' => $type,
        ]);
    }
}

function pacesFor(User $user, Carbon $today): ?array
{
    return app(TrainingPaceCalculator::class)->fromVdotResult(
        app(VdotEstimator::class)->estimate($user, $today),
    );
}

it('returns nothing when the current week has no planned sessions', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();

    $result = app(WeekSessionTypesBuilder::class)->forUser($user, Carbon::today(), null);

    expect($result)->toBe([]);
    Carbon::setTestNow();
});

it('reports one training day per session, in weekday order, and drops the rest days', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $today = Carbon::today();
    seedMixedWeek($user, $today->copy()->startOfWeek(Carbon::MONDAY));

    $result = app(WeekSessionTypesBuilder::class)->forUser($user, $today, pacesFor($user, $today));

    expect(array_column($result, 'weekday'))->toBe(['mon', 'wed', 'thu', 'sat', 'sun'])
        ->and(array_column($result, 'session_type'))->toBe(['easy', 'tempo', 'easy', 'interval', 'long'])
        ->and($result[4]['distance_km'])->toBeGreaterThan($result[0]['distance_km']);

    Carbon::setTestNow();
});

it('sizes a day exactly as the week plan Home renders does', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $today = Carbon::today();
    seedMixedWeek($user, $today->copy()->startOfWeek(Carbon::MONDAY));

    $ladder = app(WeekSessionTypesBuilder::class)->forUser($user, $today, pacesFor($user, $today));
    $home = app(CurrentWeekPlanBuilder::class)->forUser($user, $today);

    $homeKmByWeekday = [];
    foreach ($home['days'] as $day) {
        $homeKmByWeekday[strtolower(Carbon::parse($day['date'])->format('D'))] = $day['distance_km'];
    }

    foreach ($ladder as $day) {
        expect($day['distance_km'])->toBe($homeKmByWeekday[$day['weekday']]);
    }

    Carbon::setTestNow();
});

it('sizes a race day from the distance the row stores for itself', function (): void {
    Carbon::setTestNow('2026-08-12'); // a Wednesday
    $user = User::factory()->create();
    $today = Carbon::today();
    PlannedSession::factory()->for($user)->create([
        'date' => $today,
        'phase' => PlanPhase::Peak,
        'session_type' => SessionType::Race,
        'race_distance_m' => 10_000,
    ]);

    $result = app(WeekSessionTypesBuilder::class)->forUser($user, $today, pacesFor($user, $today));

    expect($result)->toHaveCount(1)
        ->and($result[0]['session_type'])->toBe('race')
        ->and($result[0]['distance_km'])->toBe(10.0);

    Carbon::setTestNow();
});
