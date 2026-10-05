<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PaceBand;
use App\Enums\SessionType;
use App\Models\PlannedSession;

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

    public const float WARMUP_KM = 2.0;

    public const float DISTANCE_TOLERANCE = 0.10;

    public const float AIM_SLACK = 0.05;

    public const string EFFORT_ZONE = 'Z4';

    public const string GATE_PACE = 'pace';

    public const string GATE_HEART_RATE = 'heart_rate';

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
     * The whole outing a trial day asks for, the warmup plus the trial
     * distance, or null on any other day.
     *
     * @param  array<string, int|float|string>|null  $context
     */
    public static function dayKm(SessionType $sessionType, ?array $context): ?float
    {
        if (! self::isTrial($context) || ! in_array($sessionType, [SessionType::Tempo, SessionType::Interval], true)) {
            return null;
        }

        return round((float) $context['distance_m'] / 1000 + self::WARMUP_KM, 1);
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
