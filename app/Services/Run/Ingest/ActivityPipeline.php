<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Models\StravaConnection;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Run\Story\Temari;
use App\Services\Strava\StravaClient;
use App\Services\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Per-activity ingest, end to end:
 *
 *   1. Fetch detail (mandatory)            → store onto activity_details
 *   2. Fetch streams (best-effort)         → store onto activity_streams
 *   3. Compute stream_summary              → time-in-zone, decoupling, best efforts, ...
 *   4. Compute Edwards TRIMP               → trimp_edwards column
 *   5. Look up weather (best-effort)       → weather_* columns
 *   6. Detect personal records             → personal_records ledger
 *   7. Mark analyzed_at                    → unlocks dashboards
 *
 * Idempotent: re-running on an already-analyzed activity refreshes all of
 * the above. Each compute step is best-effort — a failure in one (no
 * heartrate stream, weather API down, etc.) doesn't block the others.
 */
class ActivityPipeline
{
    private const int DETAIL_FETCH_MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly StravaClient $client,
        private readonly StreamAnalysis $streamAnalysis,
        private readonly TrainingLoad $trainingLoad,
        private readonly PersonalRecords $personalRecords,
        private readonly OpenMeteoClient $weather,
        private readonly RunCardFactory $cardFactory,
        private readonly Temari $temari,
    ) {
    }

    public function ingest(Activity $activity): void
    {
        $connection = $activity->user->stravaConnection;
        if ($connection === null) {
            Log::warning('ingest skipped — user has no Strava connection', [
                'activity_id' => $activity->id,
            ]);

            return;
        }

        try {
            $detail = $this->client
                ->get($connection, "/activities/{$activity->strava_external_id}")
                ->json();
        } catch (Throwable $e) {
            $this->handleDetailFailure($activity, $e);

            return;
        }

        if (! is_array($detail)) {
            $this->handleDetailFailure($activity, new RuntimeException('Strava returned non-array detail'));

            return;
        }

        $detailModel = $this->storeDetail($activity, $detail);

        $streams = $this->fetchStreams($activity, $connection);
        if ($streams !== null) {
            $this->storeStreams($activity, $streams);
        }

        $this->computeAndStoreSummary($detailModel, $streams);
        $this->lookupWeather($detailModel, $streams);
        $this->personalRecords->detectAndStore($activity, $detailModel);

        // Story layer reads the just-updated $detailModel (PR detection above
        // may have inserted rows the card/Temari mood logic cares about).
        $this->cardFactory->build($activity, $detailModel);
        $this->temari->postRunLine($activity, $detailModel);

        $activity->update([
            'analyzed_at' => now(),
            'detail_fail_count' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function storeDetail(Activity $activity, array $detail): ActivityDetail
    {
        $start = $detail['start_date_local'] ?? $detail['start_date'] ?? null;

        return ActivityDetail::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            [
                'name' => $detail['name'] ?? null,
                'start_date_local' => is_string($start) ? Carbon::parse($start) : null,
                'distance' => $detail['distance'] ?? null,
                'moving_time' => $detail['moving_time'] ?? null,
                'elapsed_time' => $detail['elapsed_time'] ?? null,
                'average_speed' => $detail['average_speed'] ?? null,
                'total_elevation_gain' => $detail['total_elevation_gain'] ?? null,
                'has_heartrate' => (bool) ($detail['has_heartrate'] ?? false),
                'average_heartrate' => $detail['average_heartrate'] ?? null,
                'max_heartrate' => $detail['max_heartrate'] ?? null,
                'average_cadence' => $detail['average_cadence'] ?? null,
                'calories' => $detail['calories'] ?? null,
                'splits_metric' => $detail['splits_metric'] ?? null,
                'summary_polyline' => $detail['map']['summary_polyline'] ?? null,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchStreams(Activity $activity, StravaConnection $connection): ?array
    {
        try {
            $streams = $this->client
                ->get($connection, "/activities/{$activity->strava_external_id}/streams", [
                    'keys' => 'time,distance,heartrate,cadence,velocity_smooth,altitude,latlng',
                    'key_by_type' => 'true',
                ])
                ->json();

            return is_array($streams) ? $streams : null;
        } catch (Throwable $e) {
            Log::info('streams fetch failed (non-fatal)', [
                'activity_id' => $activity->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $streams
     */
    private function storeStreams(Activity $activity, array $streams): void
    {
        ActivityStream::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            ['data' => $streams],
        );
    }

    /**
     * @param  array<string, mixed>|null  $streams
     */
    private function computeAndStoreSummary(ActivityDetail $detail, ?array $streams): void
    {
        if ($streams === null) {
            return;
        }

        /** @var array<string, array{lo: int, hi: int}> $hrZones */
        $hrZones = config('runner.hr_zones');
        $optimalCadence = (int) config('runner.optimal_cadence_spm');

        $summary = $this->streamAnalysis->compute(
            $streams,
            $hrZones,
            is_array($detail->splits_metric) ? $detail->splits_metric : null,
            $optimalCadence,
        );

        $minutesInZone = $summary['time_in_zone_min'] ?? null;
        $trimp = is_array($minutesInZone) ? $this->trainingLoad->edwardsTrimp($minutesInZone) : null;

        $detail->update([
            'stream_summary' => $summary === [] ? null : $summary,
            'trimp_edwards' => $trimp,
        ]);
    }

    /**
     * Best-effort weather lookup. Reads first lat/lng from the streams blob;
     * if either coords or start time are missing, no weather is stored.
     *
     * @param  array<string, mixed>|null  $streams
     */
    private function lookupWeather(ActivityDetail $detail, ?array $streams): void
    {
        if ($streams === null || $detail->start_date_local === null) {
            return;
        }

        $latlng = $streams['latlng']['data'][0] ?? null;
        if (! is_array($latlng) || count($latlng) !== 2) {
            return;
        }

        $startedAt = CarbonImmutable::instance($detail->start_date_local);
        $snapshot = $this->weather->fetchForActivity(
            (float) $latlng[0],
            (float) $latlng[1],
            $startedAt,
        );

        if ($snapshot === null) {
            return;
        }

        $detail->update([
            'weather_temp_c' => $snapshot->tempC,
            'weather_humidity_pct' => $snapshot->humidityPct,
            'weather_rain_detected' => $snapshot->rainDetected,
        ]);
    }

    private function handleDetailFailure(Activity $activity, Throwable $reason): void
    {
        $count = $activity->detail_fail_count + 1;

        if ($count >= self::DETAIL_FETCH_MAX_ATTEMPTS) {
            $activity->update([
                'detail_fail_count' => $count,
                'analyzed_at' => now(),
            ]);
            Log::warning('detail fetch giving up after max attempts', [
                'activity_id' => $activity->id,
                'attempts' => $count,
                'reason' => $reason->getMessage(),
            ]);

            return;
        }

        $activity->update(['detail_fail_count' => $count]);
        Log::info('detail fetch failed; will retry on next run', [
            'activity_id' => $activity->id,
            'attempts' => $count,
            'reason' => $reason->getMessage(),
        ]);
    }
}
