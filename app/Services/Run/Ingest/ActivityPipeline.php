<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Events\ActivityIngested;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\StravaConnection;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Gamification\MilestoneDetector;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Run\Story\Temari;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\StravaClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use App\Services\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

// Idempotent: re-running refreshes all artifacts. Each compute step is
// best-effort — one failure (no HR stream, weather API down) doesn't
// block the others.
class ActivityPipeline
{
    private const int DETAIL_FETCH_MAX_ATTEMPTS = Activity::MAX_DETAIL_FETCH_ATTEMPTS;

    public function __construct(
        private readonly StravaClient $client,
        private readonly StreamAnalysis $streamAnalysis,
        private readonly TrainingLoad $trainingLoad,
        private readonly PersonalRecords $personalRecords,
        private readonly OpenMeteoClient $weather,
        private readonly RunCardFactory $cardFactory,
        private readonly Temari $temari,
        private readonly WeeklyAggregator $weeklyAggregator,
        private readonly MilestoneDetector $milestoneDetector,
        private readonly AppConfig $config,
    ) {
    }

    public function ingest(Activity $activity): void
    {
        // Strava kill-switch source of truth on the ingest side: leave the stub
        // pending (analyzed_at stays null) so the drain resumes on re-enable.
        if (! $this->config->boolean(AppConfigKey::StravaEnabled)) {
            return;
        }

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
        } catch (StravaRateLimitedException|StravaCircuitOpenException $e) {
            // Don't burn a retry on a fixed backoff: let the queued job re-queue
            // with an exponential delay (see IngestActivityJob).
            throw $e;
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

        $this->computeAndStoreSummary($activity, $detailModel, $streams);
        $this->lookupWeather($detailModel, $streams);

        // analyzed_at + the derivation/story layer commit atomically: if any of
        // PR / card / Temari / milestone throws, analyzed_at rolls back too, so
        // the stub stays drainable instead of being stranded "analyzed" with a
        // half-built story and no AI cascade. All of these are same-connection DB
        // writes (no HTTP, no queued jobs — the Strava/weather fetches above ran
        // outside the txn), and within the txn the uncommitted analyzed_at is
        // visible to the AnalyzedScope relations the story layer re-loads.
        DB::transaction(function () use ($activity, $detailModel): void {
            $activity->update([
                'analyzed_at' => now(),
                'detail_fail_count' => 0,
            ]);

            $newPrCategories = $this->personalRecords->detectAndStore($activity, $detailModel);

            // Story layer must run after PR detection — Temari mood reads PR rows.
            $this->cardFactory->build($activity, $detailModel);
            $this->temari->postRunLine($activity, $detailModel);
            $this->milestoneDetector->detect($activity, $detailModel, $newPrCategories);
        });

        // After commit only: a story-layer throw rolled the whole block back, so
        // the cascade never fires for a half-ingested run. Hand off the AI fan-out
        // to a queued listener (see DispatchPostRunAnalysis).
        ActivityIngested::dispatch($activity->id);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function storeDetail(Activity $activity, array $detail): ActivityDetail
    {
        $start = $detail['start_date_local'] ?? $detail['start_date'] ?? null;
        // start_latlng is null/empty for non-GPS activities (treadmill, manual).
        $latlng = is_array($detail['start_latlng'] ?? null) && count($detail['start_latlng']) === 2
            ? $detail['start_latlng']
            : null;

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
                'start_lat' => $latlng === null ? null : (float) $latlng[0],
                'start_lng' => $latlng === null ? null : (float) $latlng[1],
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
        } catch (StravaRateLimitedException|StravaCircuitOpenException $e) {
            // The detail already stored; a rate-limited stream fetch should
            // re-queue the whole job rather than silently drop the streams.
            throw $e;
        } catch (RequestException $e) {
            // 4xx (404 = no streams for this activity) is permanent and
            // expected for treadmill / manual runs; 5xx is transient. Either
            // way streams are best-effort, so we log and continue without them.
            Log::info('streams fetch failed (non-fatal)', [
                'activity_id' => $activity->id,
                'status' => $e->response->status(),
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (Throwable $e) {
            Log::info('streams fetch failed (non-fatal)', [
                'activity_id' => $activity->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Pull the HTTP status off a failed Strava request, when the throwable
     * carries one. Non-HTTP throwables (transport errors, runtime guards)
     * return null and are treated as transient.
     */
    private function httpStatus(Throwable $reason): ?int
    {
        if ($reason instanceof RequestException) {
            return $reason->response->status();
        }

        return null;
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
    private function computeAndStoreSummary(Activity $activity, ActivityDetail $detail, ?array $streams): void
    {
        if ($streams === null) {
            return;
        }

        // Take the activity explicitly rather than via $detail->activity: during
        // ingest the row is still a stub, and the AnalyzedScope would resolve the
        // belongsTo back to null.
        $profile = $activity->user->hrProfile();
        $hrZones = $profile['hr_zones'];
        $optimalCadence = $profile['optimal_cadence_spm'];

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
     * Recompute a single activity's `stream_summary` / `trimp_edwards` from its
     * ALREADY-STORED streams using the user's CURRENT heart-rate zones, then
     * rebuild that week's snapshot forward. Forward-only: makes ZERO Strava HTTP
     * calls, so a user-initiated "Baca ulang" can refresh one block with new
     * zones without re-ingesting from Strava. No-op when the activity has no
     * stored streams or no detail row.
     */
    public function recomputeSummary(Activity $activity): void
    {
        $detail = $activity->detail;
        $stream = $activity->stream;
        if ($detail === null || $stream === null || $stream->data === []) {
            return;
        }

        $this->computeAndStoreSummary($activity, $detail, $stream->data);

        if ($detail->start_date_local !== null) {
            $this->weeklyAggregator->rebuildForwardFrom($activity->user, $detail->start_date_local);
        }
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

        try {
            $snapshot = $this->weather->fetchForActivity(
                (float) $latlng[0],
                (float) $latlng[1],
                $startedAt,
            );
        } catch (Throwable $e) {
            // Weather is best-effort (see class header): a failure here must never
            // block ingest, or the activity is left an un-ingestable stub forever.
            Log::warning('weather lookup failed (non-fatal)', [
                'activity_id' => $detail->activity_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

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
        // A 4xx is permanent (404 deleted, 403 unshared): no amount of retrying
        // recovers it. Stamp analyzed_at so the row is treated as handled and
        // we stop re-fetching it on every sync. 5xx and transport errors stay
        // transient and fall through to the retry counter below.
        $status = $this->httpStatus($reason);
        if ($status !== null && $status >= 400 && $status < 500) {
            $activity->update([
                'detail_fail_count' => $activity->detail_fail_count + 1,
                'analyzed_at' => now(),
            ]);
            Log::info('detail fetch hit a permanent 4xx; marking handled', [
                'activity_id' => $activity->id,
                'status' => $status,
                'reason' => $reason->getMessage(),
            ]);

            return;
        }

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
