<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Services\Run\Metrics\SessionIntent;
use App\Services\Run\Metrics\StreamSummary;

/**
 * A stable hash over the run data that MATERIALLY drives its per-run narration,
 * used to decide whether a re-sync changed enough to be worth re-narrating.
 *
 * Values are bucketed to the granularity the narration actually speaks to, so
 * Strava's byte-level jitter on a re-fetch never churns a regeneration while a
 * real correction (a shifted split, a mood flip) does. Cross-activity inputs
 * (training load, 28-day baseline, chain continuity) are deliberately excluded:
 * they move on their own from other runs and aren't a signal that THIS run changed.
 */
final class MaterialFingerprint
{
    public static function forActivity(Activity $activity): string
    {
        $detail = $activity->detail;
        $material = $detail === null ? [] : self::materialFrom($activity, $detail);
        ksort($material);

        // xxh128 (non-cryptographic): a change-detection digest, not a security
        // primitive — speed + collision-resistance at this scale is all we need.
        return hash('xxh128', (string) json_encode($material));
    }

    /**
     * @return array<string, mixed>
     */
    private static function materialFrom(Activity $activity, ActivityDetail $detail): array
    {
        $summary = $detail->streamSummary();

        return [
            'distance' => self::bucket($detail->distance, 10),   // nearest 10 m
            'moving_time' => $detail->moving_time,
            'avg_hr' => self::bucket($detail->average_heartrate),
            'max_hr' => $detail->max_heartrate,
            'avg_cadence' => self::bucket($detail->average_cadence),
            'trimp' => self::bucket($detail->trimp_edwards),
            'weather_temp_c' => $detail->weather_temp_c,
            'weather_humidity_pct' => $detail->weather_humidity_pct,
            'weather_rain' => $detail->weather_rain_detected,
            'weather_rain_forecast' => $detail->weather_rain_is_forecast,
            'wind_speed' => $detail->weather_wind_speed_kmh,
            'wind_gust' => $detail->weather_wind_gust_kmh,
            'wind_dir' => $detail->weather_wind_direction_deg,
            'decoupling' => self::bucket(self::summaryFloat($summary, 'decoupling_pct')),
            'negative_split' => (bool) ($summary['negative_split'] ?? false),
            'zone_pct' => self::bucketedZones($summary),
            'pace_variability' => self::bucket(self::summaryFloat($summary, 'pace_variability_sec')),
            'ascent_m' => self::bucket(self::summaryFloat($summary, 'ascent_m')),
            'max_grade_pct' => self::half(self::summaryFloat($summary, 'max_grade_pct')),
            'gap_pace' => $summary['gap_pace'] ?? null,
            'mood' => self::mood($activity),
            'session_intent' => SessionIntent::forDetail($detail)['intent'],
            'has_pr' => PersonalRecord::query()->where('activity_id', $activity->id)->exists(),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, int>
     */
    private static function bucketedZones(array $summary): array
    {
        $zonePct = StreamSummary::zonePct($summary);
        $rounded = [];
        foreach ($zonePct as $zone => $pct) {
            $rounded[$zone] = (int) round((float) $pct);
        }
        ksort($rounded);

        return $rounded;
    }

    private static function mood(Activity $activity): ?string
    {
        return StoryLine::query()
            ->where('activity_id', $activity->id)
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->value('mood');
    }

    /** Round to the nearest $step (default 1), or null. */
    private static function bucket(?float $value, int $step = 1): ?int
    {
        return $value === null ? null : (int) (round($value / $step) * $step);
    }

    /** Round to the nearest 0.5, or null. */
    private static function half(?float $value): ?float
    {
        return $value === null ? null : round($value * 2) / 2;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summaryFloat(array $summary, string $key): ?float
    {
        return isset($summary[$key]) ? (float) $summary[$key] : null;
    }
}
