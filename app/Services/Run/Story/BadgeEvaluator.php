<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Enums\Badge;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\StreamSummary;

/**
 * Pure badge rules: every whole-history fact arrives on the {@see CardContext},
 * so nothing here touches the database.
 */
final class BadgeEvaluator
{
    public const int LONG_SLOW_DISTANCE_THRESHOLD_M = 12_000;

    private const int LONG_SLOW_DISTANCE_DURATION_S = 3_600;

    private const int PACE_SPEEDSTER_SEC_PER_KM = 300;

    private const int ELEVATION_GAIN_M = 200;

    /** A short punchy climb earns Climber even without big total gain. */
    private const float MAX_GRADE_CLIMBER_PCT = 8.0;

    /** At or below this temperature (Celsius) a run counts as cold. */
    private const int COLD_TEMP_C = 20;

    /**
     * Average HR below this fraction of max HR counts as an easy effort.
     */
    private const float EASY_HR_RATIO = 0.78;

    /**
     * Compute all badges for a run. Split into original + expanded badge groups
     * to keep cognitive complexity manageable.
     *
     * @return list<string>
     */
    public function evaluate(ActivityDetail $detail, StreamSummary $summary, CardContext $context): array
    {
        return array_merge(
            $this->originalBadges($detail, $summary),
            $this->expandedBadges($detail, $summary, $context),
        );
    }

    public function isAerobicDiscipline(ActivityDetail $detail, StreamSummary $summary): bool
    {
        $distance = $detail->distance ?? 0;
        if ($distance < 10_000) {
            return false;
        }

        return $summary->hardZoneShare() < 10.0;
    }

    /**
     * Original 6 badges: weather, time-of-day, distance, split, discipline.
     *
     * @return list<string>
     */
    private function originalBadges(ActivityDetail $detail, StreamSummary $summary): array
    {
        $badges = [];

        if (($detail->weather_temp_c ?? 0) >= 31) {
            $badges[] = Badge::HeatTamer->value;
        }
        if ($detail->weather_rain_detected === true) {
            $badges[] = Badge::RainWarrior->value;
        }
        if (($detail->weather_wind_speed_kmh ?? 0) >= 20) {
            $badges[] = Badge::Headwind->value;
        }
        if ($detail->start_date_local !== null && (int) $detail->start_date_local->format('H') < 6) {
            $badges[] = Badge::EarlyBird->value;
        }
        if ($this->isLongSlowDistance($detail, $summary)) {
            $badges[] = Badge::LongSlowDistance->value;
        }
        if ($summary->negativeSplit() === true) {
            $badges[] = Badge::NegativeSplit->value;
        }
        if ($this->isAerobicDiscipline($detail, $summary)) {
            $badges[] = Badge::HeldBack->value;
        }

        return $badges;
    }

    /**
     * 9 expanded badges: night, elevation, first-run, pace, distance, zones,
     * effort.
     *
     * @return list<string>
     */
    private function expandedBadges(ActivityDetail $detail, StreamSummary $summary, CardContext $context): array
    {
        $badges = [];
        $distance = (float) ($detail->distance ?? 0);
        $hour = $this->startHour($detail);

        if ($hour !== null && ($hour < 5 || $hour >= 21)) {
            $badges[] = Badge::NightOwl->value;
        }
        if (($detail->total_elevation_gain ?? 0) >= self::ELEVATION_GAIN_M
            || ($summary->maxGradePct() ?? 0.0) >= self::MAX_GRADE_CLIMBER_PCT) {
            $badges[] = Badge::Climber->value;
        }
        if ($context->isFirstRunEver) {
            $badges[] = Badge::FirstTimer->value;
        }

        $paceSec = $detail->paceSecPerKm();
        if ($paceSec !== null && $paceSec < self::PACE_SPEEDSTER_SEC_PER_KM) {
            $badges[] = Badge::Speedster->value;
        }
        if ($distance >= 21_097.5) {
            $badges[] = Badge::LongHauler->value;
        }

        return array_merge($badges, $this->zoneAndEffortBadges($detail, $summary, $context, $hour));
    }

    /**
     * Zone-based and effort-based badges: Z2 Master, Cold Runner, All Out, Easy Miles.
     *
     * @return list<string>
     */
    private function zoneAndEffortBadges(ActivityDetail $detail, StreamSummary $summary, CardContext $context, ?int $hour): array
    {
        $badges = [];

        $zonePct = $summary->zonePct();
        if (($zonePct['Z2'] ?? 0) > 80.0) {
            $badges[] = Badge::Z2Master->value;
        }
        if ($this->isColdRun($detail, $hour)) {
            $badges[] = Badge::ColdRunner->value;
        }
        if ($this->isHardEffort($detail, $context)) {
            $badges[] = Badge::AllOut->value;
        }
        if ($this->isEasyEffort($detail, $context)) {
            $badges[] = Badge::EasyMiles->value;
        }

        return $badges;
    }

    /**
     * Cold run for the ❄️ Cold Runner badge. Prefer a real weather reading; when
     * none is stored, fall back to a stricter pre-dawn window than EarlyBird's so
     * the two badges stay distinct instead of double-awarding every early run.
     */
    private function isColdRun(ActivityDetail $detail, ?int $hour): bool
    {
        $temp = $detail->weather_temp_c;
        if ($temp !== null) {
            return $temp <= self::COLD_TEMP_C;
        }

        return $hour !== null && $hour < 5;
    }

    /**
     * Extract the start-hour (0-23) from the detail's start_date_local, or null.
     */
    private function startHour(ActivityDetail $detail): ?int
    {
        return $detail->start_date_local !== null
            ? (int) $detail->start_date_local->format('H')
            : null;
    }

    /**
     * Hard effort: average HR > 85% of the athlete's max HR.
     */
    private function isHardEffort(ActivityDetail $detail, CardContext $context): bool
    {
        $ratio = $this->hrRatio($detail, $context);

        return $ratio !== null && $ratio > 0.85;
    }

    /**
     * Easy effort: average HR below EASY_HR_RATIO of the athlete's max HR.
     *
     * The textbook figure is 70%, but that describes a recovery jog, not an easy
     * run: a genuine Z2 session sits nearer 75-80% of max, so 70% awarded the
     * badge to nobody. The threshold names the effort runners actually call easy.
     */
    private function isEasyEffort(ActivityDetail $detail, CardContext $context): bool
    {
        $ratio = $this->hrRatio($detail, $context);

        return $ratio !== null && $ratio < self::EASY_HR_RATIO;
    }

    /**
     * Average HR as a fraction of the athlete's true max HR, taken from the
     * user's hrProfile rather than this run's own peak HR. Null when avg HR is
     * missing. The athlete max falls back to the hrProfile default, which is
     * never zero, so the denominator is always positive.
     */
    private function hrRatio(ActivityDetail $detail, CardContext $context): ?float
    {
        $avg = $detail->average_heartrate;

        if ($avg === null) {
            return null;
        }

        $athleteMaxHr = $context->athleteMaxHr;

        if ($athleteMaxHr === null || $athleteMaxHr <= 0) {
            return null;
        }

        return $avg / $athleteMaxHr;
    }

    private function isLongSlowDistance(ActivityDetail $detail, StreamSummary $summary): bool
    {
        $distance = $detail->distance ?? 0;
        $elapsed = $detail->elapsed_time ?? 0;
        if ($distance < self::LONG_SLOW_DISTANCE_THRESHOLD_M || $elapsed < self::LONG_SLOW_DISTANCE_DURATION_S) {
            return false;
        }

        return $summary->hardZoneShare() < 25.0;
    }
}
