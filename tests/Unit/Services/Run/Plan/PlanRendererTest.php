<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\SegmentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

// PlannedSession::factory()->make() never persists the session itself, but
// its 'user_id' => User::factory() default still resolves (and creates a
// real row) regardless of make() vs create() -- a well-known Eloquent
// factory quirk, not something these tests can avoid by using make().
uses(RefreshDatabase::class);

const RENDERER_PACES = ['easy' => 360, 'marathon' => 300, 'threshold' => 270, 'interval' => 240];

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

it('dayPayload generates segments fresh from the stored session when there is no clamp', function (): void {
    $session = PlannedSession::factory()->make([
        'date' => '2026-08-10',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Long,
        'pinned' => true,
    ]);

    $payload = PlanRenderer::dayPayload(
        $session,
        Carbon::parse('2026-08-01'),
        null,
        [],
        false,
        false,
        20.0,
        1.0,
        RENDERER_PACES,
        PlannedSessionStatus::Planned,
    );

    expect($payload['session_type'])->toBe('long')
        ->and($payload['segments'])->toHaveCount(1)
        ->and($payload['segments'][0]['key'])->toBe('main')
        ->and($payload['distance_km'])->toBe(20.0)
        ->and($payload['pinned'])->toBeTrue()
        ->and($payload['clamp_note'])->toBeNull();
});

it('dayPayload substitutes the clamp\'s segments for today\'s row only', function (): void {
    $today = Carbon::parse('2026-08-10');
    $todaySession = PlannedSession::factory()->make([
        'date' => $today,
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Long,
        'pinned' => false,
    ]);
    $clampSegments = SegmentGenerator::generate(SessionType::Easy, PlanPhase::Build, false, false, 20.0, 1.0, RENDERER_PACES);
    $clamp = [
        'session_type' => SessionType::Easy,
        'segments' => $clampSegments,
        'core_km' => SegmentGenerator::coreKmFor(SessionType::Easy, false, 20.0, 1.0),
        'note' => 'Clamped for low readiness.',
    ];

    $payload = PlanRenderer::dayPayload($todaySession, $today, $clamp, [], false, false, 20.0, 1.0, RENDERER_PACES, PlannedSessionStatus::Planned);

    expect($payload['session_type'])->toBe('easy')
        ->and($payload['segments'])->toBe(array_map(fn ($s) => $s->toArray(), $clampSegments))
        ->and($payload['distance_km'])->toBe($clamp['core_km'])
        ->and($payload['clamp_note'])->toBe('Clamped for low readiness.');

    $tomorrowSession = PlannedSession::factory()->make([
        'date' => $today->copy()->addDay(),
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Long,
        'pinned' => false,
    ]);
    $unaffected = PlanRenderer::dayPayload($tomorrowSession, $today, $clamp, [], false, false, 20.0, 1.0, RENDERER_PACES, PlannedSessionStatus::Planned);

    expect($unaffected['session_type'])->toBe('long')
        ->and($unaffected['clamp_note'])->toBeNull();
});

it('dayPayload applies a redistributed volume scale for a non-today day', function (): void {
    $session = PlannedSession::factory()->make([
        'date' => '2026-08-12',
        'phase' => PlanPhase::Build,
        'session_type' => SessionType::Long,
        'pinned' => false,
    ]);

    $unscaled = PlanRenderer::dayPayload($session, Carbon::parse('2026-08-10'), null, [], false, false, 20.0, 1.0, RENDERER_PACES, PlannedSessionStatus::Planned);
    $scaled = PlanRenderer::dayPayload($session, Carbon::parse('2026-08-10'), null, ['2026-08-12' => 0.5], false, false, 20.0, 1.0, RENDERER_PACES, PlannedSessionStatus::Planned);

    expect($scaled['distance_km'])->toBe(round($unscaled['distance_km'] * 0.5, 1));
});

it('dayPayload returns no segments and a null distance_km for a rest day', function (): void {
    $session = PlannedSession::factory()->rest()->make(['date' => '2026-08-10', 'phase' => PlanPhase::Build]);

    $payload = PlanRenderer::dayPayload(
        $session,
        Carbon::parse('2026-08-01'),
        null,
        [],
        false,
        false,
        20.0,
        1.0,
        RENDERER_PACES,
        PlannedSessionStatus::Planned,
    );

    expect($payload['segments'])->toBe([])
        ->and($payload['distance_km'])->toBe(0.0);
});

it('dayPayload still fills distance_km with no VDOT estimate yet — only segment minutes go null', function (): void {
    $session = PlannedSession::factory()->make(['date' => '2026-08-10', 'phase' => PlanPhase::Build, 'session_type' => SessionType::Easy]);

    $payload = PlanRenderer::dayPayload($session, Carbon::parse('2026-08-01'), null, [], false, true, 20.0, 1.0, null, PlannedSessionStatus::Planned);

    expect($payload['segments'][0]['minutes'])->toBeNull()
        ->and($payload['distance_km'])->toBe(13.0); // 20.0 * 0.65 (isPrimaryEasy=true), pace-independent
});
