<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\IntentVerdict;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
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

    /**
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     */
    public function resolve(
        SessionType $type,
        PlanPhase $phase,
        ?float $raceDistanceM,
        ?int $raceGoalTimeSec,
        ?array $paces,
        ?IntentVerdict $previousVerdict = null,
        ?int $previousHardMinutes = null,
        ?int $hardMinutesAvailable = null,
    ): IntensityPrescription {
        if (! $type->isQuality()) {
            return new IntensityPrescription(0, null, null, 'easy volume');
        }

        [$target, $band, $raceContext] = $this->target($type, $phase, $raceDistanceM, $raceGoalTimeSec, $paces);
        if ($target === 0 || $band === null) {
            return new IntensityPrescription(0, null, null, 'easy volume', $raceContext);
        }

        $coldStart = $type === SessionType::Interval
            ? 2 * (self::INTERVAL_REP_MINUTES[$phase->value] ?? 3)
            : ($raceContext === null ? 20 : 15);
        $minutes = $previousHardMinutes === null
            ? min($target, $coldStart)
            : $this->progressed($previousHardMinutes, $previousVerdict, $type, $phase, $target);
        $reason = $previousHardMinutes === null ? 'conservative start with sparse comparable evidence' : match ($previousVerdict) {
            IntentVerdict::Hit => 'progressed after the latest comparable session was hit',
            IntentVerdict::TooHard => 'stepped down after the latest comparable session was too hard',
            default => 'held after the latest comparable session',
        };

        $minutes = min($target, $minutes);
        if ($hardMinutesAvailable !== null && $minutes > $hardMinutesAvailable) {
            $minutes = $this->wholeWorkUnits($type, $phase, $target, $hardMinutesAvailable);
            $reason = 'bounded by this week’s easy-time reserve';
        }
        if ($type === SessionType::Interval) {
            $rep = self::INTERVAL_REP_MINUTES[$phase->value] ?? 3;
            $minutes = intdiv($minutes, $rep) * $rep;
        }

        $minimum = $type === SessionType::Interval
            ? 2 * (self::INTERVAL_REP_MINUTES[$phase->value] ?? 3)
            : 10;
        if ($minutes < $minimum) {
            return new IntensityPrescription(0, null, null, 'easy because the week has no safe room for meaningful quality', $raceContext);
        }

        $pace = $this->pace($band, $raceContext, $paces);
        return new IntensityPrescription($minutes, $band, $pace, $reason, $raceContext);
    }

    /**
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     * @return array{0: int, 1: PaceBand|null, 2: array{distance_m: int, goal_pace_sec_per_km: int, kind: 'marathon'|'ultra'}|null}
     */
    private function target(SessionType $type, PlanPhase $phase, ?float $distanceM, ?int $goalTimeSec, ?array $paces): array
    {
        $raceSpecific = $distanceM !== null && $distanceM >= WeekPlanBuilder::MARATHON_DISTANCE_THRESHOLD_M && $goalTimeSec !== null && $goalTimeSec > 0;
        $context = $raceSpecific ? [
            'distance_m' => (int) round($distanceM),
            'goal_pace_sec_per_km' => (int) round($goalTimeSec / ($distanceM / 1000)),
            'kind' => $distanceM > 42_195.0 ? 'ultra' : 'marathon',
        ] : null;

        $ultraGoalIsEasy = $raceSpecific
            && $context !== null
            && $distanceM > 42_195.0
            && $paces !== null
            && $context['goal_pace_sec_per_km'] >= $paces['easy'];
        if ($ultraGoalIsEasy && in_array($type, [SessionType::Tempo, SessionType::Long], true)) {
            return [0, PaceBand::Easy, $context];
        }

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

    private function progressed(int $minutes, ?IntentVerdict $verdict, SessionType $type, PlanPhase $phase, int $target): int
    {
        if ($type === SessionType::Interval) {
            $rep = self::INTERVAL_REP_MINUTES[$phase->value] ?? 3;

            return match ($verdict) {
                IntentVerdict::Hit => $minutes + $rep,
                IntentVerdict::TooHard => max($rep, $minutes - $rep),
                default => $minutes,
            };
        }

        $workUnit = SegmentGenerator::workUnitMinutes($type, $phase, $target);

        return match ($verdict) {
            IntentVerdict::Hit => (int) round($minutes * 1.1),
            IntentVerdict::TooHard => max(0, $minutes - $workUnit),
            default => $minutes,
        };
    }

    private function wholeWorkUnits(SessionType $type, PlanPhase $phase, int $target, int $available): int
    {
        $unit = SegmentGenerator::workUnitMinutes($type, $phase, $target);

        return intdiv(max(0, $available), $unit) * $unit;
    }

    /**
     * @param array{distance_m: int, goal_pace_sec_per_km: int, kind: 'marathon'|'ultra'}|null $context
     * @param array{easy: int, marathon: int, threshold: int, interval: int}|null $paces
     */
    private function pace(PaceBand $band, ?array $context, ?array $paces): ?int
    {
        if ($paces === null) {
            return null;
        }

        if ($band !== PaceBand::Marathon || $context === null) {
            return $paces[$band->value];
        }

        $goalPace = (int) $context['goal_pace_sec_per_km'];

        if ($context['kind'] === 'ultra') {
            return $goalPace;
        }

        return max($goalPace, $paces['marathon']);
    }

    /**
     * Comparable evidence is keyed by stimulus, not only by the broad row
     * type. A marathon-specific Tempo must not teach a threshold Tempo, and
     * an ultra Long must not teach an ordinary Long.
     */
    public static function familyKey(SessionType $type, ?float $distanceM, ?int $goalTimeSec): string
    {
        $raceSpecific = $distanceM !== null
            && $distanceM >= WeekPlanBuilder::MARATHON_DISTANCE_THRESHOLD_M
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

        return match ($type) {
            SessionType::Tempo => 'race_tempo',
            SessionType::Long => 'race_long',
            default => $type->value,
        };
    }
}
