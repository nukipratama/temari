<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\Agent\Tools\PlanContextTool;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-08 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * Three weeks around Thursday 2026-10-08: the week before, the current week
 * and the week after. Overrides replace a day's attributes by date.
 *
 * @param  array<string, array<string, mixed>>  $overrides
 */
function parityPlan(array $overrides = []): User
{
    $user = User::factory()->create();
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-09-24 07:00:00',
        'distance' => 10_000,
    ]);
    $multiplier = 6.8 / app(TrainingBaseline::class)->forUser($user, Carbon::today())['long_run_km'];
    $hardTempo = ['prescribed_hard_minutes' => 20, 'prescribed_pace_band' => PaceBand::Threshold];

    $days = [
        '2026-09-28' => [SessionType::Rest, []],
        '2026-09-29' => [SessionType::Easy, ['status' => PlannedSessionStatus::Done]],
        '2026-09-30' => [SessionType::Tempo, [...$hardTempo, 'status' => PlannedSessionStatus::Done]],
        '2026-10-01' => [SessionType::Rest, []],
        '2026-10-02' => [SessionType::Easy, ['status' => PlannedSessionStatus::Missed]],
        '2026-10-03' => [SessionType::Rest, []],
        '2026-10-04' => [SessionType::Long, ['status' => PlannedSessionStatus::Done]],
        '2026-10-05' => [SessionType::Rest, []],
        '2026-10-06' => [SessionType::Easy, ['status' => PlannedSessionStatus::Done]],
        '2026-10-07' => [SessionType::Tempo, [...$hardTempo, 'status' => PlannedSessionStatus::Done]],
        '2026-10-08' => [SessionType::Easy, []],
        '2026-10-09' => [SessionType::Interval, ['prescribed_hard_minutes' => 16, 'prescribed_pace_band' => PaceBand::Interval]],
        '2026-10-10' => [SessionType::Easy, []],
        '2026-10-11' => [SessionType::Long, []],
        '2026-10-12' => [SessionType::Rest, []],
        '2026-10-13' => [SessionType::Easy, []],
        '2026-10-14' => [SessionType::Rest, []],
        '2026-10-15' => [SessionType::Tempo, $hardTempo],
        '2026-10-16' => [SessionType::Rest, []],
        '2026-10-17' => [SessionType::Easy, []],
        '2026-10-18' => [SessionType::Long, []],
    ];

    foreach ($days as $date => [$type, $attributes]) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'phase' => PlanPhase::Build,
            'session_type' => $type,
            'volume_multiplier' => $multiplier,
            ...$attributes,
            ...($overrides[$date] ?? []),
        ]);
    }

    return $user;
}

/** @return array<string, string> */
function parityCardTypes(User $user): array
{
    $types = [];
    foreach (app(PlanPageAssembler::class)->weeks($user, Carbon::today()) as $week) {
        foreach ($week['days'] as $day) {
            $types[$day['date']] = $day['session_type'];
        }
    }

    return $types;
}

/** @return array<string, string> */
function parityNarratedTypes(User $user, string $from, string $through): array
{
    $tool = new PlanContextTool($user, Carbon::parse($from), Carbon::parse($through), app(TrainingBaseline::class), app(VdotEstimator::class), app(TrainingPaceCalculator::class));

    return collect($tool->handle([])['days'])->pluck('session_type', 'date')->all();
}

function parityOverreaching(User $user): void
{
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => '2026-10-11',
        'form_status' => 'overreaching',
        'monotony' => 1.0,
    ]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed()->create())->create([
        'start_date_local' => '2026-10-07 12:00:00',
        'elapsed_time' => 3600,
        'distance' => 10_000,
        'has_heartrate' => true,
        'trimp_edwards' => 130.0,
        'stream_summary' => ['time_in_zone_min' => ['Z2' => 20, 'Z4' => 12]],
    ]);
}

it('names the same session on the card and to the narrator for a past day, every current-week day and a future week', function (): void {
    $user = parityPlan();

    $card = parityCardTypes($user);
    $narrated = parityNarratedTypes($user, '2026-09-28', '2026-10-18');

    expect($narrated)->toHaveCount(21)
        ->and($narrated)->toBe(array_intersect_key($card, $narrated))
        ->and($narrated['2026-09-30'])->toBe('tempo')
        ->and($narrated['2026-10-04'])->toBe('long')
        ->and($narrated['2026-10-09'])->toBe('interval')
        ->and($narrated['2026-10-15'])->toBe('tempo');
});

it('names the same advised session for today on the card and to the narrator', function (): void {
    $user = parityPlan(['2026-10-08' => ['session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => null, 'prescribed_pace_band' => null]]);
    parityOverreaching($user);

    $card = parityCardTypes($user);
    $narrated = parityNarratedTypes($user, '2026-09-28', '2026-10-18');

    expect($card['2026-10-08'])->toBe('easy')
        ->and($narrated)->toBe(array_intersect_key($card, $narrated));
});

it('names an easy-prescribed tempo row as the easy run on the card and to the narrator in the current week', function (): void {
    $user = parityPlan(['2026-10-10' => ['session_type' => SessionType::Tempo, 'prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null]]);

    $card = parityCardTypes($user);
    $narrated = parityNarratedTypes($user, '2026-09-28', '2026-10-18');

    expect($card['2026-10-10'])->toBe('easy')
        ->and($narrated['2026-10-10'])->toBe($card['2026-10-10'])
        ->and($narrated)->toBe(array_intersect_key($card, $narrated));
});

it('names an easy-prescribed tempo row as the easy run on the card and to the narrator in a future week', function (): void {
    $user = parityPlan(['2026-10-15' => ['prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null]]);

    $card = parityCardTypes($user);
    $narrated = parityNarratedTypes($user, '2026-09-28', '2026-10-18');

    expect($card['2026-10-15'])->toBe('easy')
        ->and($narrated['2026-10-15'])->toBe($card['2026-10-15'])
        ->and($narrated)->toBe(array_intersect_key($card, $narrated));
});

it('names an easy-prescribed tempo row as the easy run on the card and to the narrator in a past week', function (): void {
    $user = parityPlan(['2026-09-30' => ['prescribed_hard_minutes' => 0, 'prescribed_pace_band' => null]]);

    $card = parityCardTypes($user);
    $narrated = parityNarratedTypes($user, '2026-09-28', '2026-10-18');

    expect($card['2026-09-30'])->toBe('easy')
        ->and($narrated['2026-09-30'])->toBe($card['2026-09-30'])
        ->and($narrated)->toBe(array_intersect_key($card, $narrated));
});
