<?php

declare(strict_types=1);

use App\Services\IntervalsIcu\IntervalsIcuWorkoutMapper;

const EASY_WINDOW = ['fast_sec_per_km' => 393, 'slow_sec_per_km' => 452];
const THRESHOLD_WINDOW = ['fast_sec_per_km' => 332, 'slow_sec_per_km' => 359];
const INTERVAL_WINDOW = ['fast_sec_per_km' => 301, 'slow_sec_per_km' => 327];
const MARATHON_WINDOW = ['fast_sec_per_km' => 383, 'slow_sec_per_km' => 403];
const RACE_WINDOW = ['fast_sec_per_km' => 366, 'slow_sec_per_km' => 395];

/**
 * @param  array{fast_sec_per_km: int, slow_sec_per_km: int}|null  $window
 * @return array{key: string, minutes: float|null, km: float|null, zone: string, pace_label: string, pace_sec_per_km: int|null, pace_range: array{fast_sec_per_km: int, slow_sec_per_km: int}|null}
 */
function icuSegment(string $key, ?float $minutes, ?float $km, string $label, ?array $window, string $zone = 'Z2', ?int $pace = 400): array
{
    return ['key' => $key, 'minutes' => $minutes, 'km' => $km, 'zone' => $zone, 'pace_label' => $label, 'pace_sec_per_km' => $window === null ? null : $pace, 'pace_range' => $window];
}

/**
 * @param  list<array<string, mixed>>  $segments
 * @param  array{distance_m: float|int, aim_time_sec: int}|null  $timeTrial
 * @return array<string, mixed>
 */
function icuDay(string $type, float $km, array $segments, ?array $timeTrial = null, ?string $purpose = null): array
{
    return ['session_type' => $type, 'distance_km' => $km, 'segments' => $segments, 'time_trial' => $timeTrial, 'purpose' => $purpose];
}

/** @param  array<string, mixed>  $day */
function icuSteps(array $day): string
{
    /** @phpstan-ignore argument.type */
    $workout = IntervalsIcuWorkoutMapper::map($day, null);
    expect($workout)->not->toBeNull()
        ->and($workout?->description)->toStartWith(IntervalsIcuWorkoutMapper::OWNER."\n\n");

    return substr((string) $workout?->description, strlen(IntervalsIcuWorkoutMapper::OWNER) + 2);
}

/** @param  array<string, mixed>  $day */
function icuName(array $day): string
{
    /** @phpstan-ignore argument.type */
    return (string) IntervalsIcuWorkoutMapper::map($day, null)?->name;
}

it('sends an easy run as one active step run to a distance', function (): void {
    $day = icuDay('easy', 8.0, [icuSegment('main', 58.0, 8.0, 'easy', EASY_WINDOW)]);

    expect(icuName($day))->toBe('Easy 8 km')
        ->and(icuSteps($day))->toBe('- 8km 6:33-7:32/km Pace intensity=active');
});

it('opens the description with the day\'s purpose, then who owns the workout', function (): void {
    $day = icuDay('easy', 8.0, [icuSegment('main', 58.0, 8.0, 'easy', EASY_WINDOW)], purpose: 'easy means easy.');

    /** @phpstan-ignore argument.type */
    expect(IntervalsIcuWorkoutMapper::map($day, null)?->description)->toBe(implode("\n", [
        'easy means easy.',
        'from temari · changes with your plan',
        '',
        '- 8km 6:33-7:32/km Pace intensity=active',
    ]));
});

it('sends a plain long run as one active step', function (): void {
    $day = icuDay('long', 14.0, [icuSegment('main', 101.0, 14.0, 'easy', EASY_WINDOW)]);

    expect(icuName($day))->toBe('Long run 14 km')
        ->and(icuSteps($day))->toBe('- 14km 6:33-7:32/km Pace intensity=active');
});

it('labels the easy running around a long run\'s marathon-pace block as warm-up and cool-down', function (): void {
    $day = icuDay('long', 11.0, [
        icuSegment('easy', 58.0, 8.0, 'easy', EASY_WINDOW),
        icuSegment('main', 20.0, 3.1, 'marathon', MARATHON_WINDOW, 'Z3'),
        icuSegment('easy', 10.0, 1.4, 'easy', EASY_WINDOW),
    ]);

    expect(icuSteps($day))->toBe(implode("\n", [
        '- 8km 6:33-7:32/km Pace intensity=warmup',
        '- 20m 6:23-6:43/km Pace intensity=active',
        '- 1.4km 6:33-7:32/km Pace intensity=cooldown',
    ]));
});

it('runs a fast-finish long run as a warm-up then the fast block', function (): void {
    $day = icuDay('long', 13.0, [
        icuSegment('easy', 80.0, 11.0, 'easy', EASY_WINDOW),
        icuSegment('main', 15.0, 2.6, 'threshold', THRESHOLD_WINDOW, 'Z4'),
    ]);

    expect(icuSteps($day))->toBe(implode("\n", [
        '- 11km 6:33-7:32/km Pace intensity=warmup',
        '- 15m 5:32-5:59/km Pace intensity=active',
    ]));
});

it('writes a tempo day\'s repeated blocks as one repeat, adding a jog after the last', function (): void {
    $block = icuSegment('main', 8.0, 1.4, 'threshold', THRESHOLD_WINDOW, 'Z4');
    $jog = icuSegment('recovery', 2.0, 0.3, 'easy', EASY_WINDOW);
    $day = icuDay('tempo', 6.6, [icuSegment('warmup', 10.0, 1.4, 'easy', EASY_WINDOW), $block, $jog, $block, $jog, $block, icuSegment('easy', 7.9, 1.1, 'easy', EASY_WINDOW)]);

    expect(icuName($day))->toBe('Tempo 3×8 min')
        ->and(icuSteps($day))->toBe(implode("\n", [
            '- 10m 6:33-7:32/km Pace intensity=warmup',
            '',
            '3x',
            '- 8m 5:32-5:59/km Pace intensity=active',
            '- 2m 6:33-7:32/km Pace intensity=recovery',
            '',
            '- 1.1km 6:33-7:32/km Pace intensity=active',
        ]));
});

it('writes a continuous tempo block as one step', function (): void {
    $day = icuDay('tempo', 6.0, [
        icuSegment('warmup', 10.0, 1.4, 'easy', EASY_WINDOW),
        icuSegment('main', 25.0, 4.3, 'threshold', THRESHOLD_WINDOW, 'Z4'),
    ]);

    expect(icuName($day))->toBe('Tempo 25 min')
        ->and(icuSteps($day))->toBe(implode("\n", [
            '- 10m 6:33-7:32/km Pace intensity=warmup',
            '- 25m 5:32-5:59/km Pace intensity=active',
        ]));
});

it('writes interval reps as one repeat, whatever the rep and jog lengths', function (int $reps, float $rep, float $jog): void {
    $work = icuSegment('interval', $rep, 0.6, 'interval', INTERVAL_WINDOW, 'Z5');
    $recovery = icuSegment('recovery', $jog, 0.3, 'easy', EASY_WINDOW);
    $segments = [icuSegment('warmup', 12.0, 1.7, 'easy', EASY_WINDOW)];
    for ($i = 0; $i < $reps; $i++) {
        $segments[] = $work;
        if ($i < $reps - 1) {
            $segments[] = $recovery;
        }
    }
    $day = icuDay('interval', 6.0, $segments);

    expect(icuName($day))->toBe("Intervals {$reps}×".(int) $rep.' min')
        ->and(icuSteps($day))->toBe(implode("\n", [
            '- 12m 6:33-7:32/km Pace intensity=warmup',
            '',
            "{$reps}x",
            '- '.(int) $rep.'m 5:01-5:27/km Pace intensity=active',
            '- '.(int) $jog.'m 6:33-7:32/km Pace intensity=recovery',
        ]));
})->with([
    'five threes' => [5, 3.0, 2.0],
    'four twos' => [4, 2.0, 3.0],
]);

it('keeps a last rep run at a different pace outside the repeat', function (): void {
    $rep = icuSegment('interval', 3.0, 0.6, 'interval', INTERVAL_WINDOW, 'Z5');
    $jog = icuSegment('recovery', 2.0, 0.3, 'easy', EASY_WINDOW);
    $last = icuSegment('interval', 3.0, 0.6, 'interval', THRESHOLD_WINDOW, 'Z5');
    $day = icuDay('interval', 5.0, [$rep, $jog, $rep, $jog, $last]);

    expect(icuSteps($day))->toBe(implode("\n", [
        '2x',
        '- 3m 5:01-5:27/km Pace intensity=active',
        '- 2m 6:33-7:32/km Pace intensity=recovery',
        '',
        '- 3m 5:32-5:59/km Pace intensity=active',
    ]));
});

it('runs race day to its distance and names the race', function (float $km, string $name, string $length): void {
    $day = icuDay('race', $km, [icuSegment('main', 59.0, $km, 'threshold', RACE_WINDOW, 'Z4')]);

    expect(icuName($day))->toBe($name)
        ->and(icuSteps($day))->toBe("- {$length} 6:06-6:35/km Pace intensity=active");
})->with([
    [10.0, 'Race 10K', '10km'],
    [5.0, 'Race 5K', '5km'],
    [21.1, 'Race Half marathon', '21.1km'],
    [42.2, 'Race Marathon', '42.2km'],
    [15.5, 'Race 15.5 km', '15.5km'],
]);

it('runs a time trial to its distance', function (): void {
    $day = icuDay('interval', 3.0, [icuSegment('main', 13.5, 3.0, 'interval', INTERVAL_WINDOW, 'Z5')], ['distance_m' => 3000, 'aim_time_sec' => 810]);

    expect(icuName($day))->toBe('Time trial 3K')
        ->and(icuSteps($day))->toBe('- 3km 5:01-5:27/km Pace intensity=active');
});

it('drops the open-ended easy finish, which has no length to send', function (): void {
    $day = icuDay('tempo', 5.0, [
        icuSegment('main', 20.0, 3.4, 'threshold', THRESHOLD_WINDOW, 'Z4'),
        icuSegment('easy', null, null, 'easy', null),
    ]);

    expect(icuSteps($day))->toBe('- 20m 5:32-5:59/km Pace intensity=active');
});

it('writes durations past the hour and part minutes in intervals.icu units', function (): void {
    $day = icuDay('tempo', 9.0, [
        icuSegment('warmup', 65.0, null, 'easy', EASY_WINDOW),
        icuSegment('main', 6.7, 1.2, 'threshold', THRESHOLD_WINDOW, 'Z4'),
    ]);

    expect(icuSteps($day))->toBe(implode("\n", [
        '- 1h5m 6:33-7:32/km Pace intensity=warmup',
        '- 6m42s 5:32-5:59/km Pace intensity=active',
    ]));
});

it('sends nothing for a rest day', function (): void {
    expect(IntervalsIcuWorkoutMapper::map(icuDay('rest', 0.0, []), null))->toBeNull();
});

describe('an athlete without paces', function (): void {
    $heartRate = [
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
    $day = icuDay('interval', 6.0, [
        icuSegment('warmup', 12.0, null, 'easy', null),
        icuSegment('interval', 3.0, null, 'interval', null, 'Z5'),
        icuSegment('recovery', 2.0, null, 'easy', null),
        icuSegment('interval', 3.0, null, 'interval', null, 'Z5'),
    ]);

    it('gets heart-rate targets as a share of their threshold heart rate', function () use ($day, $heartRate): void {
        /** @phpstan-ignore argument.type */
        $workout = IntervalsIcuWorkoutMapper::map($day, $heartRate);

        expect(IntervalsIcuWorkoutMapper::needsHeartRate($day))->toBeTrue()
            ->and($workout?->description)->toEndWith(implode("\n", [
                '- 12m 75-85% LTHR intensity=warmup',
                '',
                '2x',
                '- 3m 101-112% LTHR intensity=active',
                '- 2m 75-85% LTHR intensity=recovery',
            ]));
    });

    it('names a tempo day by its blocks\' distance, which is all they know', function () use ($heartRate): void {
        $block = icuSegment('main', null, 5.2, 'threshold', null, 'Z4');
        $twoBlocks = icuDay('tempo', 12.0, [icuSegment('warmup', 10.0, null, 'easy', null), $block, icuSegment('recovery', 2.0, null, 'easy', null), $block]);
        $unequal = icuDay('tempo', 12.0, [$block, icuSegment('recovery', 2.0, null, 'easy', null), icuSegment('main', null, 3.4, 'threshold', null, 'Z4')]);

        /** @phpstan-ignore argument.type */
        expect(IntervalsIcuWorkoutMapper::map($twoBlocks, $heartRate)?->name)->toBe('Tempo 2×5.2 km')
            /** @phpstan-ignore argument.type */
            ->and(IntervalsIcuWorkoutMapper::map($unequal, $heartRate)?->name)->toBe('Tempo 8.6 km');
    });

    it('is not sent while their threshold heart rate is unknown', function () use ($day): void {
        /** @phpstan-ignore argument.type */
        expect(IntervalsIcuWorkoutMapper::map($day, null))->toBeNull();
    });
});

it('needs no heart rate once every step has a pace window', function (): void {
    expect(IntervalsIcuWorkoutMapper::needsHeartRate(icuDay('easy', 8.0, [icuSegment('main', 58.0, 8.0, 'easy', EASY_WINDOW)])))->toBeFalse();
});
