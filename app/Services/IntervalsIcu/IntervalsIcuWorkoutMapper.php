<?php

declare(strict_types=1);

namespace App\Services\IntervalsIcu;

use App\Services\Run\Metrics\DecimalFormatter;
use App\Services\Run\Metrics\PaceFormatter;

/**
 * Turns a day as Plan and Today show it ({@see \App\Services\Run\Plan\PlanRenderer::dayPayload()})
 * into an intervals.icu workout: a name, then a description that opens with
 * the day's purpose and who owns the workout, followed by step lines
 * intervals.icu parses into the structured workout it forwards to the watch.
 * In that syntax `m` means minutes, so distances are always written in `km`,
 * and a step without a target is dropped by the watch export, so every step
 * carries one: its pace window, or for an athlete without paces a share of
 * their intervals.icu threshold heart rate.
 *
 * @phpstan-type Segment array{key: string, minutes: float|null, km: float|null, zone: string, pace_label: string, pace_sec_per_km: int|null, pace_range?: array{fast_sec_per_km: int, slow_sec_per_km: int}|null}
 * @phpstan-type Day array{session_type: string, distance_km: float|int|null, segments: list<Segment>, time_trial: array{distance_m: float|int, aim_time_sec: int}|null, purpose: string|null}
 * @phpstan-type HeartRate array{threshold_bpm: int, max_hr: int, hr_zones: array<string, array{lo: int, hi: int}>}
 * @phpstan-type Step array{line: string, work: bool, recovery: bool}
 */
final class IntervalsIcuWorkoutMapper
{
    public const string OWNER = 'from temari · changes with your plan';

    private const array WORK_KEYS = ['main', 'interval'];

    /**
     * @param  Day  $day
     * @param  HeartRate|null  $heartRate
     */
    public static function map(array $day, ?array $heartRate): ?IntervalsIcuWorkout
    {
        $segments = self::measured($day['segments']);
        if ($day['session_type'] === 'rest' || $segments === []) {
            return null;
        }
        if ($heartRate === null && self::needsHeartRate($day)) {
            return null;
        }

        $steps = [];
        foreach ($segments as $index => $segment) {
            $steps[] = [
                'line' => '- '.self::length($segment, $day).' '.self::target($segment, $heartRate).' intensity='.self::intensity($segments, $index, $day['session_type']),
                'work' => self::isWork($segment),
                'recovery' => $segment['key'] === 'recovery',
            ];
        }

        $note = ($day['purpose'] === null ? '' : $day['purpose']."\n").self::OWNER;

        return new IntervalsIcuWorkout(self::name($day, $segments), $note."\n\n".self::text($steps));
    }

    /**
     * Whether the day has a step with no pace window, which then needs the
     * athlete's threshold heart rate to carry a target at all.
     *
     * @param  Day  $day
     */
    public static function needsHeartRate(array $day): bool
    {
        return array_any(self::measured($day['segments']), fn ($segment) => ($segment['pace_range'] ?? null) === null);
    }

    /**
     * The open-ended "easy for the rest" step has no length to send.
     *
     * @param  list<Segment>  $segments
     * @return list<Segment>
     */
    private static function measured(array $segments): array
    {
        return array_values(array_filter($segments, static fn (array $segment): bool => $segment['minutes'] !== null || $segment['km'] !== null));
    }

    /** @param  Segment  $segment */
    private static function isWork(array $segment): bool
    {
        return in_array($segment['key'], self::WORK_KEYS, true) && $segment['pace_label'] !== 'easy';
    }

    /**
     * Easy running and race efforts are run to a distance; the work and
     * jogs of a quality day are prescribed in minutes.
     *
     * @param  Segment  $segment
     * @param  Day  $day
     */
    private static function length(array $segment, array $day): string
    {
        $byDistance = $segment['pace_label'] === 'easy' && $segment['key'] !== 'warmup' && $segment['key'] !== 'recovery'
            || $day['session_type'] === 'race'
            || $day['time_trial'] !== null;

        if ($segment['km'] !== null && ($byDistance || $segment['minutes'] === null)) {
            return DecimalFormatter::trimmed($segment['km'], 2).'km';
        }

        return self::duration((float) $segment['minutes']);
    }

    /**
     * @param  Segment  $segment
     * @param  HeartRate|null  $heartRate
     */
    private static function target(array $segment, ?array $heartRate): string
    {
        $range = $segment['pace_range'] ?? null;
        if ($range !== null) {
            return PaceFormatter::format($range['fast_sec_per_km']).'-'.PaceFormatter::format($range['slow_sec_per_km']).'/km Pace';
        }

        /** @var HeartRate $heartRate */
        $zone = $heartRate['hr_zones'][$segment['zone']];
        $low = (int) round($zone['lo'] * 100 / $heartRate['threshold_bpm']);
        $high = (int) round(min($zone['hi'], $heartRate['max_hr']) * 100 / $heartRate['threshold_bpm']);

        return "{$low}-{$high}% LTHR";
    }

    /**
     * A long run with a harder block labels its easy running around the block
     * as warm-up and cool-down, so the watch shows where the block sits.
     *
     * @param  list<Segment>  $segments
     */
    private static function intensity(array $segments, int $index, string $sessionType): string
    {
        $segment = $segments[$index];
        $work = array_keys(array_filter($segments, self::isWork(...)));

        if ($sessionType === 'long' && $work !== [] && ! self::isWork($segment)) {
            return $index < $work[0] ? 'warmup' : 'cooldown';
        }

        return match ($segment['key']) {
            'warmup' => 'warmup',
            'recovery' => 'recovery',
            default => 'active',
        };
    }

    /**
     * Steps joined into blocks, with each run of identical rep and recovery
     * pairs written once as an `Nx` repeat. A last rep without its recovery
     * still counts; the repeat then adds one after it.
     *
     * @param  list<Step>  $steps
     */
    private static function text(array $steps): string
    {
        $blocks = [];
        $plain = [];
        $count = count($steps);
        for ($i = 0; $i < $count; $i++) {
            [$reps, $consumed] = self::repeatAt($steps, $i);
            if ($reps < 2) {
                $plain[] = $steps[$i]['line'];

                continue;
            }
            if ($plain !== []) {
                $blocks[] = implode("\n", $plain);
                $plain = [];
            }
            $blocks[] = "{$reps}x\n{$steps[$i]['line']}\n{$steps[$i + 1]['line']}";
            $i += $consumed - 1;
        }
        if ($plain !== []) {
            $blocks[] = implode("\n", $plain);
        }

        return implode("\n\n", $blocks);
    }

    /**
     * How many rep and recovery pairs start at step `$i`, and how many steps they take up.
     *
     * @param  list<Step>  $steps
     * @return array{int, int}
     */
    private static function repeatAt(array $steps, int $i): array
    {
        if (! $steps[$i]['work'] || ! ($steps[$i + 1]['recovery'] ?? false)) {
            return [0, 0];
        }

        $work = $steps[$i]['line'];
        $recovery = $steps[$i + 1]['line'];
        $reps = 0;
        $j = $i;
        while (($steps[$j]['line'] ?? null) === $work) {
            $reps++;
            $j++;
            if (($steps[$j]['line'] ?? null) !== $recovery) {
                break;
            }
            $j++;
        }

        return [$reps, $j - $i];
    }

    /**
     * @param  Day  $day
     * @param  list<Segment>  $segments
     */
    private static function name(array $day, array $segments): string
    {
        if ($day['time_trial'] !== null) {
            return 'Time trial '.self::raceDistance($day['time_trial']['distance_m'] / 1000);
        }

        $km = DecimalFormatter::trimmed((float) $day['distance_km'], 2);

        return match ($day['session_type']) {
            'race' => 'Race '.self::raceDistance((float) $day['distance_km']),
            'easy' => "Easy {$km} km",
            'long' => "Long run {$km} km",
            'tempo' => 'Tempo '.self::workShape($segments),
            'interval' => 'Intervals '.self::workShape($segments),
            default => ucfirst($day['session_type'])." {$km} km",
        };
    }

    /**
     * "3×8 min" when the work is equal blocks, otherwise its total; in km
     * when the work has no minutes, as for an athlete without paces.
     *
     * @param  list<Segment>  $segments
     */
    private static function workShape(array $segments): string
    {
        $byKm = array_any($segments, static fn (array $segment): bool => self::isWork($segment) && $segment['minutes'] === null);
        $units = [];
        $current = null;
        foreach ($segments as $segment) {
            if (self::isWork($segment)) {
                $current = ($current ?? 0.0) + (float) ($byKm ? $segment['km'] : $segment['minutes']);
            } elseif ($current !== null) {
                $units[] = $current;
                $current = null;
            }
        }
        if ($current !== null) {
            $units[] = $current;
        }

        if ($byKm) {
            $amounts = array_map(static fn (float $unit): string => DecimalFormatter::trimmed($unit, 2), $units);
            $total = DecimalFormatter::trimmed(array_sum($units), 2);
            $unit = 'km';
        } else {
            $amounts = array_map(static fn (float $unit): int => (int) round($unit), $units);
            $total = array_sum($amounts);
            $unit = 'min';
        }
        if (count($amounts) > 1 && count(array_unique($amounts)) === 1) {
            return count($amounts).'×'.$amounts[0].' '.$unit;
        }

        return "{$total} {$unit}";
    }

    private static function raceDistance(float $km): string
    {
        return match (true) {
            abs($km - 42.2) < 0.3 => 'Marathon',
            abs($km - 21.1) < 0.2 => 'Half marathon',
            abs($km - round($km)) < 0.05 => ((int) round($km)).'K',
            default => DecimalFormatter::trimmed($km, 2).' km',
        };
    }

    private static function duration(float $minutes): string
    {
        $seconds = (int) round($minutes * 60);
        $hours = intdiv($seconds, 3600);
        $mins = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return ($hours > 0 ? "{$hours}h" : '').($mins > 0 ? "{$mins}m" : '').($secs > 0 ? "{$secs}s" : '');
    }
}
