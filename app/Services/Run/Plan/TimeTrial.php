<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Services\Run\Metrics\RunDistanceTimes;

/**
 * The all-out 5K or 10K that takes one quality session's place, so the
 * supported time keeps resting on fresh evidence. See
 * `docs/decisions/a-time-trial-every-six-weeks.md`.
 */
final readonly class TimeTrial
{
    public const string KIND = 'time_trial';

    public const int SHORT_DISTANCE_M = 5_000;

    public const int LONG_DISTANCE_M = 10_000;

    public const float DISTANCE_TOLERANCE = 0.10;

    public const float AIM_SLACK = 0.05;

    public const string EFFORT_ZONE = 'Z4';

    public const string GATE_PACE = 'pace';

    public const string GATE_HEART_RATE = 'heart_rate';

    public const string READ_RUN = 'run';

    public const string READ_SPLIT = 'split';

    private const string REASON = 'a time trial in place of this week’s quality session';

    public function __construct(
        public int $distanceM,
        public int $aimTimeSec,
        public bool $retry = false,
    ) {
    }

    /** 10K for a half marathon or longer goal, 5K for anything shorter and for a season with no race. */
    public static function distanceFor(?float $raceDistanceM): int
    {
        return $raceDistanceM !== null && in_array(GoalPaceWork::kindFor($raceDistanceM), [GoalPaceWork::KIND_HALF, GoalPaceWork::KIND_MARATHON], true)
            ? self::LONG_DISTANCE_M
            : self::SHORT_DISTANCE_M;
    }

    /** @return array{kind: string, distance_m: int, aim_time_sec: int, retry: int} */
    public function context(): array
    {
        return [
            'kind' => self::KIND,
            'distance_m' => $this->distanceM,
            'aim_time_sec' => $this->aimTimeSec,
            'retry' => (int) $this->retry,
        ];
    }

    public function prescription(): IntensityPrescription
    {
        return new IntensityPrescription(
            (int) ceil($this->aimTimeSec / 60),
            $this->distanceM <= self::SHORT_DISTANCE_M ? PaceBand::Interval : PaceBand::Threshold,
            (int) round($this->aimTimeSec / ($this->distanceM / 1000)),
            self::REASON,
            $this->context(),
        );
    }

    /**
     * @param  array<string, int|float|string>|null  $context
     * @phpstan-assert-if-true array{kind: string, distance_m: int, aim_time_sec: int, retry?: int} $context
     */
    public static function isTrial(?array $context): bool
    {
        return ($context['kind'] ?? null) === self::KIND;
    }

    /**
     * The trial a stored row holds, while it is still a trial: a Tempo or
     * Interval with its hard work intact.
     */
    public static function of(PlannedSession $session): ?self
    {
        $context = $session->prescription_race_context;
        if (! self::isTrial($context)
            || ! in_array($session->session_type, [SessionType::Tempo, SessionType::Interval], true)
            || IntensityPrescription::fromSession($session)?->isEasy() !== false) {
            return null;
        }

        return new self((int) $context['distance_m'], (int) $context['aim_time_sec'], (bool) ($context['retry'] ?? 0));
    }

    /**
     * The whole outing a trial day asks for, the trial distance alone like
     * race day, or null on any other day.
     *
     * @param  array<string, int|float|string>|null  $context
     */
    public static function dayKm(SessionType $sessionType, ?array $context): ?float
    {
        if (! self::isTrial($context) || ! in_array($sessionType, [SessionType::Tempo, SessionType::Interval], true)) {
            return null;
        }

        return round((float) $context['distance_m'] / 1000, 1);
    }

    /**
     * What a run on the trial day ran over the trial: the whole run, or, for
     * a run longer than the distance band, its fastest split at the trial
     * distance, with that split's heart rate when every split in it carries one.
     *
     * @return array{distance_m: float, time_sec: float|null, heart_rate: float|null, source: string, heart_rate_source: string|null}
     */
    public function reading(ActivityDetail $run): array
    {
        $meters = (float) ($run->distance ?? 0);
        $runHeartRate = $run->average_heartrate;
        $split = $meters > $this->distanceM * (1 + self::DISTANCE_TOLERANCE)
            ? RunDistanceTimes::bestSplit($run, $this->distanceM)
            : null;
        if ($split !== null) {
            return [
                'distance_m' => (float) $this->distanceM,
                'time_sec' => $split['time_sec'],
                'heart_rate' => $split['heart_rate'] ?? $runHeartRate,
                'source' => self::READ_SPLIT,
                'heart_rate_source' => match (true) {
                    $split['heart_rate'] !== null => self::READ_SPLIT,
                    $runHeartRate !== null => self::READ_RUN,
                    default => null,
                },
            ];
        }

        return [
            'distance_m' => $meters,
            'time_sec' => $run->elapsed_time === null ? ($run->moving_time === null ? null : (float) $run->moving_time) : (float) $run->elapsed_time,
            'heart_rate' => $runHeartRate,
            'source' => self::READ_RUN,
            'heart_rate_source' => $runHeartRate === null ? null : self::READ_RUN,
        ];
    }

    /**
     * Which test a reading passed, or null.
     *
     * @param  array{distance_m: float, time_sec: float|null, heart_rate: float|null, ...}  $reading
     * @param  array<string, array{lo: int, hi: int}>  $zones
     */
    public function passes(array $reading, array $zones): ?string
    {
        return $this->gate($reading['distance_m'], $reading['time_sec'], $reading['heart_rate'], $zones);
    }

    /**
     * Which test a run on the trial day passed, or null when it does not
     * count: its distance must be within the tolerance, and it must be run no
     * slower than the aim plus the slack, scaled to the run's own distance, or
     * at an average heart rate in the effort zone or above. Heart rate only
     * gates effort, it never becomes a time.
     *
     * @param  array<string, array{lo: int, hi: int}>  $zones
     */
    public function gate(float $runDistanceM, ?float $runTimeSec, ?float $averageHeartRate, array $zones): ?string
    {
        if ($runDistanceM <= 0.0 || abs($runDistanceM - $this->distanceM) / $this->distanceM > self::DISTANCE_TOLERANCE) {
            return null;
        }
        if ($runTimeSec !== null && $runTimeSec > 0.0
            && $runTimeSec * $this->distanceM / $runDistanceM <= $this->aimTimeSec * (1 + self::AIM_SLACK)) {
            return self::GATE_PACE;
        }
        $effortFloor = $zones[self::EFFORT_ZONE]['lo'] ?? null;

        return $averageHeartRate !== null && $effortFloor !== null && $averageHeartRate >= $effortFloor
            ? self::GATE_HEART_RATE
            : null;
    }

    /**
     * A past trial is skipped when the athlete excused it, nothing was run on
     * its day, or readiness eased it to an easy run or a rest.
     */
    public static function countsAsSkipped(PlannedSession $trial, bool $ran): bool
    {
        return $trial->skipped
            || ! $ran
            || ! in_array(EffectiveSession::settledTypeOf($trial), [SessionType::Tempo, SessionType::Interval], true);
    }
}
