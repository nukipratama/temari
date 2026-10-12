<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\RaceSupport;
use App\Enums\SessionType;

final class IntensityPrescriptionResolver
{
    /** @var array<string, int> */
    private const array THRESHOLD_TARGETS = ['base' => 20, 'build' => 30, 'peak' => 35, 'taper' => 20];

    /** @var array<string, int> */
    private const array INTERVAL_TARGETS = ['build' => 12, 'peak' => 16, 'taper' => 8];

    /** @var array<string, int> */
    private const array RACE_TEMPO_TARGETS = ['build' => 25, 'peak' => 35, 'taper' => 20];

    /** @var array<string, int> */
    private const array RACE_LONG_TARGETS = ['build' => 20, 'peak' => 40, 'taper' => 15];

    /** @var array<string, int> */
    private const array INTERVAL_REP_MINUTES = ['build' => 3, 'peak' => 4, 'taper' => 2];

    /** @var array<string, array<string, int>> */
    private const array GOAL_PACE_TARGETS = [
        GoalPaceWork::KIND_5K => ['build' => 15, 'peak' => 20, 'taper' => 10],
        GoalPaceWork::KIND_10K => ['build' => 21, 'peak' => 24, 'taper' => 12],
        GoalPaceWork::KIND_HALF => ['build' => 30, 'peak' => 40, 'taper' => 20],
    ];

    /**
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     */
    public static function resolve(
        SessionType $type,
        PlanPhase $phase,
        ?float $raceDistanceM,
        ?int $raceGoalTimeSec,
        ?array $paces,
        ?IntentVerdict $previousVerdict = null,
        ?int $previousHardMinutes = null,
        ?int $hardMinutesAvailable = null,
        ?GoalPaceWork $goalPace = null,
        ?int $carriedHardMinutes = null,
        ?int $peakHardMinutes = null,
    ): IntensityPrescription {
        if (! $type->isQuality()) {
            return new IntensityPrescription(0, null, null, null);
        }

        [$target, $band, $raceContext] = $goalPace === null
            ? self::target($type, $phase, $raceDistanceM, $raceGoalTimeSec)
            : self::goalPaceTarget($type, $phase, $goalPace);
        if ($target === 0 || $band === null) {
            return new IntensityPrescription(0, null, null, null, $raceContext);
        }

        $shape = GoalPaceWork::shapeOf($type, $raceContext);
        $unit = $shape === SessionType::Interval
            ? GoalPaceWork::repMinutesOf($raceContext) ?? self::INTERVAL_REP_MINUTES[$phase->value] ?? 3
            : SegmentGenerator::workUnitMinutes($shape, $phase, $target);
        $minimum = $shape === SessionType::Interval ? 2 * $unit : 10;
        if ($peakHardMinutes !== null) {
            $minutes = max($minimum, (int) round($peakHardMinutes / 2 / $unit) * $unit);
            $reason = 'half the last peak session, at its pace and rep length';
        } else {
            $coldStart = $shape === SessionType::Interval ? $minimum : ($raceContext === null ? 20 : 15);
            $minutes = $previousHardMinutes === null
                ? min($target, $coldStart)
                : self::progressed($previousHardMinutes, $previousVerdict, $shape, $unit);
            $reason = $previousHardMinutes === null ? 'conservative start with sparse comparable evidence' : match ($previousVerdict) {
                IntentVerdict::Hit => 'progressed after the latest comparable session was hit',
                IntentVerdict::TooHard => 'stepped down after the latest comparable session was too hard',
                default => 'held after the latest comparable session',
            };
            if ($carriedHardMinutes !== null) {
                $carried = min($target, $shape === SessionType::Interval ? (int) ceil($carriedHardMinutes / $unit) * $unit : $carriedHardMinutes);
                if ($carried > $minutes) {
                    $minutes = $carried;
                    $reason = 'carried over from the last build quality session';
                }
            }
            $minutes = min($target, $minutes);
        }

        if ($hardMinutesAvailable !== null && $minutes > $hardMinutesAvailable) {
            $minutes = intdiv(max(0, $hardMinutesAvailable), $unit) * $unit;
            $reason = 'bounded by this week’s easy-time reserve';
        }
        if ($shape === SessionType::Interval) {
            $minutes = intdiv($minutes, $unit) * $unit;
        }

        if ($minutes < $minimum) {
            return new IntensityPrescription(0, null, null, 'easy because the week has no safe room for meaningful quality', $raceContext);
        }

        $pace = $goalPace->goalPaceSecPerKm ?? self::pace($band, $raceContext, $paces);
        return new IntensityPrescription($minutes, $band, $pace, $reason, $raceContext);
    }

    /**
     * @return array{0: int, 1: PaceBand, 2: array{distance_m: int, goal_pace_sec_per_km: int, kind: string, band: string, final_pace_sec_per_km?: int}}
     */
    private static function goalPaceTarget(SessionType $type, PlanPhase $phase, GoalPaceWork $goalPace): array
    {
        $targets = match (true) {
            ! $goalPace->isMarathon() => self::GOAL_PACE_TARGETS[$goalPace->kind],
            $type === SessionType::Long => self::RACE_LONG_TARGETS,
            default => self::RACE_TEMPO_TARGETS,
        };
        return [$targets[$phase->value] ?? 0, $goalPace->paceBand(), $goalPace->context()];
    }

    /**
     * @return array{0: int, 1: PaceBand|null, 2: array{distance_m: int, goal_pace_sec_per_km: int, kind: 'marathon'}|null}
     */
    private static function target(SessionType $type, PlanPhase $phase, ?float $distanceM, ?int $goalTimeSec): array
    {
        $raceSpecific = $distanceM !== null && RaceSupport::isMarathonClass($distanceM) && $goalTimeSec !== null && $goalTimeSec > 0;
        $context = $raceSpecific ? [
            'distance_m' => (int) round($distanceM),
            'goal_pace_sec_per_km' => (int) round($goalTimeSec / ($distanceM / 1000)),
            'kind' => 'marathon',
        ] : null;

        if ($type === SessionType::Long) {
            return [$raceSpecific ? (self::RACE_LONG_TARGETS[$phase->value] ?? 0) : 0, $raceSpecific ? PaceBand::Marathon : null, $context];
        }
        if ($type === SessionType::Interval) {
            return [self::INTERVAL_TARGETS[$phase->value] ?? 0, PaceBand::Interval, null];
        }
        if ($raceSpecific && isset(self::RACE_TEMPO_TARGETS[$phase->value])) {
            return [self::RACE_TEMPO_TARGETS[$phase->value], PaceBand::Marathon, $context];
        }

        return [self::THRESHOLD_TARGETS[$phase->value] ?? 0, PaceBand::Threshold, null];
    }

    private static function progressed(int $minutes, ?IntentVerdict $verdict, SessionType $type, int $unit): int
    {
        if ($type === SessionType::Interval) {
            return match ($verdict) {
                IntentVerdict::Hit => $minutes + $unit,
                IntentVerdict::TooHard => max($unit, $minutes - $unit),
                default => $minutes,
            };
        }

        return match ($verdict) {
            IntentVerdict::Hit => (int) round($minutes * 1.1),
            IntentVerdict::TooHard => max(0, $minutes - $unit),
            default => $minutes,
        };
    }

    /**
     * @param array{distance_m: int, goal_pace_sec_per_km: int, kind: string, band?: string}|null $context
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     */
    private static function pace(PaceBand $band, ?array $context, ?array $paces): ?int
    {
        if ($paces === null) {
            return null;
        }

        if ($band !== PaceBand::Marathon || $context === null) {
            return $paces[$band->value];
        }

        $goalPace = (int) $context['goal_pace_sec_per_km'];

        return max($goalPace, $paces['marathon']);
    }

    /**
     * Comparable evidence is keyed by stimulus, not only by the broad row
     * type. A marathon-specific Tempo must not teach a threshold Tempo, and
     * a marathon-specific Long must not teach an ordinary Long.
     */
    public static function familyKey(SessionType $type, ?float $distanceM, ?int $goalTimeSec): string
    {
        $raceSpecific = $distanceM !== null && RaceSupport::isMarathonClass($distanceM)
            && $goalTimeSec !== null
            && $goalTimeSec > 0;
        if ($raceSpecific) {
            return match ($type) {
                SessionType::Tempo => 'race_tempo',
                SessionType::Long => 'race_long',
                default => $type->value,
            };
        }

        return $type->value;
    }

    /**
     * Resolve a historical row's comparable family from the race context that
     * was persisted with that row, rather than from today's active race.
     *
     * @param  array<string, int|float|string>|null  $raceContext
     */
    public static function familyKeyForContext(SessionType $type, ?array $raceContext): string
    {
        if ($raceContext === null) {
            return $type->value;
        }
        if (TimeTrial::isTrial($raceContext)) {
            return TimeTrial::KIND;
        }
        if (FastFinishLongRun::isFastFinish($raceContext)) {
            return FastFinishLongRun::KIND;
        }
        if (GoalPaceWork::isGoalPace($raceContext) && ($raceContext['kind'] ?? null) !== GoalPaceWork::KIND_MARATHON) {
            return 'goal_pace';
        }

        return match ($type) {
            SessionType::Tempo => 'race_tempo',
            SessionType::Long => 'race_long',
            default => $type->value,
        };
    }
}
