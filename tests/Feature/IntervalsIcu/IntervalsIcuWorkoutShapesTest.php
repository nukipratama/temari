<?php

declare(strict_types=1);

use App\Enums\PlannedSessionStatus;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Services\IntervalsIcu\IntervalsIcuWorkoutMapper;
use App\Services\Run\Plan\PlanRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

const SHAPES_PACES = ['easy' => 432, 'marathon' => 393, 'threshold' => 349, 'interval' => 317];

const SHAPES_HEART_RATE = [
    'threshold_bpm' => 170,
    'max_hr' => 190,
    'hr_zones' => [
        'Z1' => ['lo' => 100, 'hi' => 128],
        'Z2' => ['lo' => 128, 'hi' => 145],
        'Z3' => ['lo' => 145, 'hi' => 158],
        'Z4' => ['lo' => 158, 'hi' => 172],
        'Z5' => ['lo' => 172, 'hi' => 999],
    ],
];

const STEP_LINE = '/^- ((?=\d)(\d+h)?(\d+m)?(\d+s)?|\d+(\.\d+)?km) (\d+:\d\d-\d+:\d\d\/km Pace|\d+-\d+% LTHR) intensity=(warmup|active|recovery|cooldown)$/';

/**
 * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $paces
 * @return array<string, mixed>
 */
function shownDay(SessionType $type, PlanPhase $phase, ?float $raceDistanceM, ?array $paces): array
{
    $session = PlannedSession::factory()->make([
        'date' => '2026-08-10',
        'phase' => $phase,
        'session_type' => $type,
        'race_distance_m' => $type === SessionType::Race ? $raceDistanceM : null,
    ]);

    return PlanRenderer::dayPayload($session, Carbon::parse('2026-08-01'), null, [], $raceDistanceM, false, 16.0, 1.0, INF, $paces, PlannedSessionStatus::Planned, null, null, $type === SessionType::Race ? 3_600 : null, easySlowEndSecPerKm: $paces === null ? null : 452);
}

/** @return iterable<string, array{SessionType, PlanPhase, float|null}> */
function everyShownShape(): iterable
{
    foreach ([SessionType::Easy, SessionType::Long, SessionType::Tempo, SessionType::Interval, SessionType::Race] as $type) {
        foreach (PlanPhase::cases() as $phase) {
            foreach ([10_000.0, 42_195.0] as $raceDistanceM) {
                yield "{$type->value} {$phase->value} ".(int) $raceDistanceM.'m goal' => [$type, $phase, $raceDistanceM];
            }
        }
    }
}

it('maps every shown session shape to step lines intervals.icu reads, each with a target', function (SessionType $type, PlanPhase $phase, float $raceDistanceM): void {
    foreach ([[SHAPES_PACES, null], [null, SHAPES_HEART_RATE]] as [$paces, $heartRate]) {
        $day = shownDay($type, $phase, $raceDistanceM, $paces);

        /** @phpstan-ignore argument.type */
        $workout = IntervalsIcuWorkoutMapper::map($day, $heartRate);
        expect($workout)->not->toBeNull();

        $lines = array_filter(explode("\n", (string) $workout?->description), static fn (string $line): bool => str_starts_with($line, '- '));
        expect($lines)->not->toBeEmpty();
        foreach ($lines as $line) {
            expect($line)->toMatch(STEP_LINE);
        }
        expect($workout?->name)->not->toBe('')->and($workout?->name)->not->toMatch('/\b0 (min|km)$/');
    }
})->with(everyShownShape());
