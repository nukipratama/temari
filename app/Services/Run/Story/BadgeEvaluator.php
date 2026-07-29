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

    private const int PACE_KILAT_SEC_PER_KM = 300;

    private const int ELEVATION_GAIN_M = 200;

    /** A short punchy climb earns Pendaki even without big total gain. */
    private const float MAX_GRADE_PENDAKI_PCT = 8.0;

    private const int CONSECUTIVE_DAYS_RAJIN = 3;

    private const int CONSECUTIVE_DAYS_BERTURUT = 7;

    /** At or below this temperature (Celsius) a run counts as cold. */
    private const int COLD_TEMP_C = 20;

    /**
     * Average HR below this fraction of max HR counts as an easy effort.
     */
    private const float EASY_HR_RATIO = 0.78;

    /**
     * Indonesian national holidays (month-day). Covers fixed-date public
     * holidays; Easter-based ones are excluded because they shift each year.
     */
    private const array INDONESIAN_HOLIDAYS_MD = [
        '01-01', // Tahun Baru
        '01-29', // Tahun Baru Imlek
        '03-31', // Hari Nyepi
        '05-01', // Hari Buruh
        '05-20', // Hari Kebangkitan Nasional
        '06-01', // Hari Lahir Pancasila
        '08-17', // Hari Kemerdekaan
        '10-01', // Hari Kesaktian Pancasila
        '12-25', // Natal
    ];

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
            $badges[] = Badge::HariPanas->value;
        }
        if ($detail->weather_rain_detected === true) {
            $badges[] = Badge::PejuangHujan->value;
        }
        if (($detail->weather_wind_speed_kmh ?? 0) >= 20) {
            $badges[] = Badge::LawanAngin->value;
        }
        if ($detail->start_date_local !== null && (int) $detail->start_date_local->format('H') < 6) {
            $badges[] = Badge::AnakPagi->value;
        }
        if ($this->isLongSlowDistance($detail, $summary)) {
            $badges[] = Badge::LongSlowDistance->value;
        }
        if ($summary->negativeSplit() === true) {
            $badges[] = Badge::NegativeSplit->value;
        }
        if ($this->isAerobicDiscipline($detail, $summary)) {
            $badges[] = Badge::TahanDiri->value;
        }

        return $badges;
    }

    /**
     * 12 expanded badges: night, elevation, first-run, streaks, pace,
     * distance, zones, effort, holiday.
     *
     * @return list<string>
     */
    private function expandedBadges(ActivityDetail $detail, StreamSummary $summary, CardContext $context): array
    {
        $badges = [];
        $distance = (float) ($detail->distance ?? 0);
        $hour = $this->startHour($detail);
        $streak = $context->consecutiveDaysBefore;

        if ($hour !== null && ($hour < 5 || $hour >= 21)) {
            $badges[] = Badge::AnakMalam->value;
        }
        if (($detail->total_elevation_gain ?? 0) >= self::ELEVATION_GAIN_M
            || ($summary->maxGradePct() ?? 0.0) >= self::MAX_GRADE_PENDAKI_PCT) {
            $badges[] = Badge::Pendaki->value;
        }
        if ($context->isFirstRunEver) {
            $badges[] = Badge::PertamaKali->value;
        }
        if ($streak + 1 >= self::CONSECUTIVE_DAYS_RAJIN) {
            $badges[] = Badge::Rajin->value;
        }

        $paceSec = $detail->paceSecPerKm();
        if ($paceSec !== null && $paceSec < self::PACE_KILAT_SEC_PER_KM) {
            $badges[] = Badge::Kilat->value;
        }
        if ($distance >= 21_097.5) {
            $badges[] = Badge::Jauh->value;
        }

        $badges = array_merge($badges, $this->zoneAndEffortBadges($detail, $summary, $context, $hour));

        if ($streak + 1 >= self::CONSECUTIVE_DAYS_BERTURUT) {
            $badges[] = Badge::Berturut->value;
        }
        if ($this->isIndonesianHoliday($detail)) {
            $badges[] = Badge::HariSpesial->value;
        }

        return $badges;
    }

    /**
     * Zone-based and effort-based badges: Z2 Master, Anak Dingin, Keras, Santai.
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
            $badges[] = Badge::AnakDingin->value;
        }
        if ($this->isHardEffort($detail, $context)) {
            $badges[] = Badge::Keras->value;
        }
        if ($this->isEasyEffort($detail, $context)) {
            $badges[] = Badge::Santai->value;
        }

        return $badges;
    }

    /**
     * Cold run for the ❄️ Anak Dingin badge. Prefer a real weather reading; when
     * none is stored, fall back to a stricter pre-dawn window than AnakPagi's so
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
     * Whether the run's start_date_local falls on an Indonesian national holiday.
     */
    private function isIndonesianHoliday(ActivityDetail $detail): bool
    {
        $startDate = $detail->start_date_local;

        if ($startDate === null) {
            return false;
        }

        $md = $startDate->format('m-d');

        return in_array($md, self::INDONESIAN_HOLIDAYS_MD, strict: true);
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
