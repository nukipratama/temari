<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\AdaptationReason;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StoryLine;
use App\Enums\SessionType;
use App\Services\Run\Metrics\ReadinessCeiling;
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
    /**
     * What a clamp explanation actually speaks to, deliberately coarser than
     * the clamp itself. The ceiling is recomputed from {@see \App\Services\Run\Metrics\TrainingLoad}
     * on every ingest, so it drifts a little with each run logged; fingerprinting
     * the exact figures would re-bill this line several times on the one kind of
     * day it exists for. The band, the type it was downgraded to, and whether the
     * athlete has already run are the whole substance of the sentence — a
     * ceiling that slides within its own band changes nothing worth saying.
     */
    /** @param list<string> $readinessReasons */
    public static function forClamp(ReadinessCeiling $ceiling, SessionType $clampedTo, bool $hasRunToday, array $readinessReasons = []): string
    {
        return self::digest([
            'ceiling' => $ceiling->value,
            'clamped_to' => $clampedTo->value,
            'has_run_today' => $hasRunToday,
            ...($readinessReasons === [] ? [] : ['readiness_reasons' => $readinessReasons]),
        ]);
    }

    /**
     * What a season's blurb needs to react to: the sustained-ahead signal and
     * the current week's recorded adaptation. Everything else about a season's
     * material (race, window, goals) is fixed at creation — a mode switch opens
     * a new {@see \App\Models\Season} row rather than mutating this one.
     */
    public static function forSeason(
        bool $sustainedAheadOfRacePace,
        ?AdaptationReason $adaptationReason = null,
        bool $deload = false,
    ): string {
        return self::digest([
            'sustained_ahead_of_race_pace' => $sustainedAheadOfRacePace,
            'current_week_adaptation_reason' => $adaptationReason?->value,
            'current_week_adaptation_deload' => $deload,
        ]);
    }

    public static function forActivity(Activity $activity): string
    {
        $detail = $activity->detail;
        return self::digest($detail === null ? [] : self::materialFrom($activity, $detail));
    }

    /**
     * The material a Trends range's read speaks to: {@see \App\Services\AI\Agent\Tools\TrendRangeTool}'s
     * own output for the range plus plan-adherence counts, rounded to the
     * granularity the narration actually reads at rather than the tools' raw
     * precision. A scheduled re-read only spends its cadence when one of those
     * figures has actually moved.
     *
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $adherence
     */
    public static function forTrendRead(array $totals, array $adherence = []): string
    {
        return self::digest([
            'current' => self::roundedPeriod($totals['current']),
            'comparison' => self::roundedPeriod($totals['comparison']),
            'ctl_start' => self::bucket($totals['ctl_start']),
            'ctl_end' => self::bucket($totals['ctl_end']),
            'vdot_start' => self::bucket($totals['vdot_start']),
            'vdot_end' => self::bucket($totals['vdot_end']),
            'avg_monotony' => self::bucket($totals['avg_monotony']),
            'avg_strain' => self::bucket($totals['avg_strain']),
            'adherence' => self::roundedAdherence($adherence),
        ]);
    }

    /**
     * @param  array<string, mixed>  $adherence
     * @return array<string, mixed>
     */
    private static function roundedAdherence(array $adherence): array
    {
        return [
            'prescribed' => $adherence['prescribed'] ?? null,
            'done' => $adherence['done'] ?? null,
            'partial' => $adherence['partial'] ?? null,
            'missed' => $adherence['missed'] ?? null,
            'overreached' => $adherence['overreached'] ?? null,
            'excused' => $adherence['excused'] ?? null,
            'ran_anyway' => $adherence['ran_anyway'] ?? null,
            'mean_compliance' => self::bucket($adherence['mean_compliance'] ?? null),
        ];
    }

    /**
     * @param  array{runs: int, distance_km: float, trimp_total: float|null}  $period
     * @return array{runs: int, distance_km: float, trimp_total: int|null}
     */
    private static function roundedPeriod(array $period): array
    {
        return [
            'runs' => $period['runs'],
            'distance_km' => round($period['distance_km'], 1),
            'trimp_total' => self::bucket($period['trimp_total']),
        ];
    }

    /**
     * @param  array<string, mixed>  $material
     */
    private static function digest(array $material): string
    {
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
        $summary = StreamSummary::fromArray($detail->streamSummary());

        return [
            'distance' => self::bucket($detail->distance, 10),   // nearest 10 m
            'elapsed_time' => $detail->elapsed_time,
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
            'decoupling' => self::bucket($summary->decouplingPct()),
            'negative_split' => $summary->negativeSplit() === true,
            'zone_pct' => self::bucketedZones($summary),
            'pace_variability' => self::bucket($summary->paceVariabilitySec()),
            'elevation_gain_m' => self::bucket($detail->total_elevation_gain),
            'max_grade_pct' => self::half($summary->maxGradePct()),
            'gap_pace' => $summary->gapPace(),
            'partial_pace' => $summary->partialSplit()['pace'] ?? null,
            'mood' => self::mood($activity),
            'session_intent' => SessionIntent::forDetail($detail)['intent'],
            'has_pr' => PersonalRecord::query()->where('activity_id', $activity->id)->exists(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private static function bucketedZones(StreamSummary $summary): array
    {
        $zonePct = $summary->zonePct();
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
}
