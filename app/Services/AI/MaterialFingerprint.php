<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
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
     * The material a day's plan blurb speaks to, mirroring what
     * {@see \App\Services\AI\Agent\Tools\PlanDayTool} hands the model. The
     * long-run baseline is passed in rather than resolved here: it is one
     * lookup per user, and the caller is already walking seven days.
     */
    public static function forPlannedSession(PlannedSession $session, ?float $longRunBaselineKm): string
    {
        return self::digest([
            'session_type' => $session->session_type->value,
            'phase' => $session->phase->value,
            // Cast, not read raw: a freshly-created model carries null here
            // while a reloaded one carries false, and both mean "not skipped".
            'skipped' => (bool) $session->skipped,
            // Drives the prescribed distance the blurb quotes, so a moved
            // baseline changes what the day should say.
            'long_run_km' => self::half($longRunBaselineKm),
            // Only ever present on a race day, whose distance comes from the
            // goal rather than the baseline above — an athlete who swaps a 10K
            // for a half on the same date changes nothing else here. Added
            // conditionally so every already-stamped row keeps its digest
            // rather than the new key re-narrating everyone's whole week.
            ...($session->race_distance_m === null ? [] : ['race_distance_m' => $session->race_distance_m]),
            // The day flipping to credited turns the blurb from a label into a
            // read of what happened, so it has to re-narrate once. Added
            // conditionally for the same reason race_distance_m is: an ungraded
            // day keeps the digest it already carries, so shipping this does not
            // re-narrate every athlete's whole week. The SCORE is deliberately
            // out — it moves with every run that lands, and the verdict is what
            // changes the sentence. The intent verdict rides along with status
            // for the same reason: a second run that flips hit -> missed
            // changes what the read says even when the distance status does not.
            ...($session->status->isCredited() ? [
                'status' => $session->status->value,
                'intent_verdict' => $session->intent_verdict?->value,
            ] : []),
        ]);
    }

    /**
     * What a clamp explanation actually speaks to, deliberately coarser than
     * the clamp itself. The ceiling is recomputed from {@see \App\Services\Run\Metrics\TrainingLoad}
     * on every ingest, so it drifts a little with each run logged; fingerprinting
     * the exact figures would re-bill this line several times on the one kind of
     * day it exists for. The band, the type it was downgraded to, and whether the
     * athlete has already run are the whole substance of the sentence — a
     * ceiling that slides within its own band changes nothing worth saying.
     */
    public static function forClamp(ReadinessCeiling $ceiling, SessionType $clampedTo, bool $hasRunToday): string
    {
        return self::digest([
            'ceiling' => $ceiling->value,
            'clamped_to' => $clampedTo->value,
            'has_run_today' => $hasRunToday,
        ]);
    }

    public static function forActivity(Activity $activity): string
    {
        $detail = $activity->detail;
        return self::digest($detail === null ? [] : self::materialFrom($activity, $detail));
    }

    /**
     * The material a Trends range's read speaks to: {@see \App\Services\AI\Agent\Tools\TrendRangeTool}'s
     * own output for the range, rounded to the granularity the narration
     * actually reads at rather than the tool's raw precision — km to 1
     * decimal, run counts as whole numbers, load/fitness/form (TRIMP, CTL,
     * VDOT, monotony, strain) to whole numbers. A scheduled re-read only
     * spends its cadence when one of those figures has actually moved.
     *
     * @param  array<string, mixed>  $totals  {@see \App\Services\AI\Agent\Tools\TrendRangeTool::handle()}'s return value.
     */
    public static function forTrendRead(array $totals): string
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
        ]);
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
