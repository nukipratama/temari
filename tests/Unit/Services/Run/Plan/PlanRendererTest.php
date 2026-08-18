<?php

declare(strict_types=1);

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Services\Run\Plan\PlanRenderer;
use Illuminate\Support\Carbon;

it('weekPhasesAndMultipliers reads each week\'s phase from its first session', function (): void {
    $sessionsByWeek = collect([
        '2026-08-03' => collect(PlannedSession::factory()->count(2)->make(['phase' => PlanPhase::Base])),
        '2026-08-10' => collect(PlannedSession::factory()->count(2)->make(['phase' => PlanPhase::Build])),
    ]);

    [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);

    expect($phaseByWeek->get('2026-08-03'))->toBe(PlanPhase::Base)
        ->and($phaseByWeek->get('2026-08-10'))->toBe(PlanPhase::Build)
        ->and($multiplierByWeek['2026-08-03'])->toBe(1.0)
        ->and($multiplierByWeek['2026-08-10'])->toBe(1.0);
});

it('weekPhasesAndMultipliers ramps a multi-week Build block relative to its own start', function (): void {
    $sessionsByWeek = collect([
        '2026-08-03' => collect(PlannedSession::factory()->count(2)->make(['phase' => PlanPhase::Build])),
        '2026-08-10' => collect(PlannedSession::factory()->count(2)->make(['phase' => PlanPhase::Build])),
        '2026-08-17' => collect(PlannedSession::factory()->count(2)->make(['phase' => PlanPhase::Build])),
    ]);

    [$phaseByWeek, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek);

    expect($multiplierByWeek['2026-08-03'])->toBe(1.0)
        ->and($multiplierByWeek['2026-08-10'])->toBeGreaterThan($multiplierByWeek['2026-08-03'])
        ->and($multiplierByWeek['2026-08-17'])->toBeGreaterThan($multiplierByWeek['2026-08-10']);
});

it('dayPayload uses the stored session when there is no clamp or redistribution', function (): void {
    $session = PlannedSession::factory()->make([
        'date' => '2026-08-10',
        'session_type' => SessionType::Long,
        'distance_band' => DistanceBand::Long,
        'pace_band' => PaceBand::Easy,
        'pinned' => true,
    ]);

    $payload = PlanRenderer::dayPayload(
        $session,
        Carbon::parse('2026-08-01'),
        null,
        [],
        20.0,
        1.0,
        ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        PlannedSessionStatus::Planned,
    );

    expect($payload['session_type'])->toBe('long')
        ->and($payload['distance_band'])->toBe('long')
        ->and($payload['pace_sec_per_km'])->toBe(360)
        ->and($payload['pinned'])->toBeTrue()
        ->and($payload['clamp_note'])->toBeNull();
});

it('dayPayload substitutes the clamp for today\'s row only', function (): void {
    $today = Carbon::parse('2026-08-10');
    $todaySession = PlannedSession::factory()->make([
        'date' => $today,
        'session_type' => SessionType::Long,
        'distance_band' => DistanceBand::Long,
        'pinned' => false,
    ]);
    $clamp = [
        'session_type' => SessionType::Easy,
        'distance_band' => DistanceBand::Short,
        'pace_band' => PaceBand::Easy,
        'note' => 'Clamped for low readiness.',
    ];

    $payload = PlanRenderer::dayPayload($todaySession, $today, $clamp, [], 20.0, 1.0, null, PlannedSessionStatus::Planned);

    expect($payload['session_type'])->toBe('easy')
        ->and($payload['distance_band'])->toBe('short')
        ->and($payload['clamp_note'])->toBe('Clamped for low readiness.');

    $tomorrowSession = PlannedSession::factory()->make([
        'date' => $today->copy()->addDay(),
        'session_type' => SessionType::Long,
        'distance_band' => DistanceBand::Long,
        'pinned' => false,
    ]);
    $unaffected = PlanRenderer::dayPayload($tomorrowSession, $today, $clamp, [], 20.0, 1.0, null, PlannedSessionStatus::Planned);

    expect($unaffected['session_type'])->toBe('long')
        ->and($unaffected['clamp_note'])->toBeNull();
});

it('dayPayload substitutes a redistributed band for a non-today day', function (): void {
    $session = PlannedSession::factory()->make([
        'date' => '2026-08-12',
        'distance_band' => DistanceBand::Long,
        'pinned' => false,
    ]);

    $payload = PlanRenderer::dayPayload(
        $session,
        Carbon::parse('2026-08-10'),
        null,
        ['2026-08-12' => DistanceBand::Short],
        20.0,
        1.0,
        null,
        PlannedSessionStatus::Planned,
    );

    expect($payload['distance_band'])->toBe('short');
});

it('dayPayload has a null pace when the session is a rest day', function (): void {
    $session = PlannedSession::factory()->rest()->make(['date' => '2026-08-10']);

    $payload = PlanRenderer::dayPayload(
        $session,
        Carbon::parse('2026-08-01'),
        null,
        [],
        20.0,
        1.0,
        ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240],
        PlannedSessionStatus::Planned,
    );

    expect($payload['pace_band'])->toBeNull()
        ->and($payload['pace_sec_per_km'])->toBeNull();
});
