<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Actions\Gamification\DetectActivityMilestonesAction;
use App\Enums\IngestState;
use App\Events\ActivityIngested;
use App\Jobs\Geo\ResolveActivityLocationJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\StravaConnection;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\HeartRateZones;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Run\Story\Temari;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshTransientException;
use App\Services\Strava\RunSportType;
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
        private readonly DetectActivityMilestonesAction $milestoneDetector,
        private readonly AppConfig $config,
    ) {
    }

    public function ingest(Activity $activity): void
    {
        if ($this->stravaIngestDisabled()) {
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
            // Rethrow rather than retry here: IngestActivityJob re-queues with its
            // own exponential backoff.
            throw $e;
        } catch (StravaConnectionRevokedException|StravaTokenRefreshFailedException $e) {
            $this->markConnectionRevoked($activity, $connection, $e);

            return;
        } catch (StravaTokenRefreshTransientException $e) {
            // A momentary token-endpoint blip (401 / 429 / 5xx / timeout) is not a
            // deauthorization: rethrow so IngestActivityJob retries with backoff
            // instead of revoking a healthy connection.
            throw $e;
        } catch (Throwable $e) {
            $this->handleDetailFailure($activity, $e);

            return;
        }

        if (! is_array($detail)) {
            $this->handleDetailFailure($activity, new RuntimeException('Strava returned non-array detail'));

            return;
        }

        if (RunSportType::isExplicitlyNotRun($detail)) {
            $this->rejectNonRunUpload($activity, $detail);

            return;
        }

        $detailModel = $this->storeDetail($activity, $detail);

        $streams = $this->fetchStreams($activity, $connection);
        if ($streams !== null) {
            $this->storeStreams($activity, $streams);
        }

        $this->computeAndStoreSummary($activity, $detailModel, $streams);
        $this->lookupWeather($detailModel, $streams);

        // Wrapped in a transaction so analyzed_at rolls back with the story layer:
        // a PR / card / Temari / milestone throw must leave the stub drainable,
        // never stranded "analyzed" with a half-built story and no AI cascade.
        DB::transaction(function () use ($activity, $detailModel): void {
            $activity->update([
                'analyzed_at' => now(),
                'ingest_state' => IngestState::Detailed,
                'detail_fail_count' => 0,
            ]);

            $newPrCategories = $this->personalRecords->detectAndStore($activity, $detailModel);

            // Story layer must run after PR detection — Temari mood reads PR rows.
            $this->cardFactory->build($activity, $detailModel);
            $this->temari->postRunLine($activity, $detailModel);
            ($this->milestoneDetector)($activity, $detailModel, $newPrCategories);
        });

        $this->dispatchIngestedEvent($activity);
        $this->scheduleLocationResolution($detailModel);
    }

    /**
     * Fired only once the transaction above commits: a story-layer throw rolls
     * analyzed_at back too, so a half-ingested run must never reach the AI fan-out.
     */
    private function dispatchIngestedEvent(Activity $activity): void
    {
        ActivityIngested::dispatch($activity->id);
    }

    /**
     * Reverse-geocodes the start point so location_name is populated for GPS
     * runs; skipped for non-GPS activities (treadmill / manual). Dispatched
     * afterCommit so the queued job never reads a detail row the rolled-back
     * transaction never wrote — geo:backfill-locations catches transient misses.
     */
    private function scheduleLocationResolution(ActivityDetail $detail): void
    {
        if ($detail->start_lat === null || $detail->start_lng === null) {
            return;
        }

        ResolveActivityLocationJob::dispatch($detail->id)->afterCommit();
    }

    /**
     * Strava kill-switch, checked on the ingest side: leaves the stub pending
     * (analyzed_at stays null) so the drain resumes once re-enabled.
     */
    private function stravaIngestDisabled(): bool
    {
        return ! $this->config->boolean(AppConfigKey::StravaEnabled);
    }

    /**
     * A 401, or a permanent invalid_grant on refresh, means the athlete
     * deauthorized Strava. detail_fail_count is deliberately left untouched —
     * a revocation is not the activity's fault and must not burn its retry
     * budget (mirrors SyncActivitiesJob).
     */
    private function markConnectionRevoked(Activity $activity, StravaConnection $connection, Throwable $e): void
    {
        $connection->markRevoked();
        Log::warning('ingest revoked connection after Strava auth failure', [
            'activity_id' => $activity->id,
            'reason' => $e->getMessage(),
        ]);
    }

    /**
     * The webhook fires for every activity type; the detail payload carries the
     * authoritative sport_type. Rejects here so a ride / walk / swim never mints
     * a PR / card / weekly snapshot or bills the AI narrator — the poll path
     * filters these upstream, this is the webhook's equivalent choke point.
     *
     * @param  array<string, mixed>  $detail
     */
    private function rejectNonRunUpload(Activity $activity, array $detail): void
    {
        Log::info('ingest dropped a non-run activity', [
            'activity_id' => $activity->id,
            'sport_type' => $detail['sport_type'] ?? $detail['type'] ?? null,
        ]);
        $activity->delete();
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
                'laps' => $detail['laps'] ?? null,
                'summary_polyline' => $detail['map']['summary_polyline'] ?? null,
                'start_lat' => $latlng === null ? null : (float) $latlng[0],
                'start_lng' => $latlng === null ? null : (float) $latlng[1],
                'suffer_score' => $detail['suffer_score'] ?? null,
                'workout_type' => $detail['workout_type'] ?? null,
                'elev_high' => $detail['elev_high'] ?? null,
                'elev_low' => $detail['elev_low'] ?? null,
                'device_name' => $detail['device_name'] ?? null,
                'average_watts' => $detail['average_watts'] ?? null,
                'max_speed' => $detail['max_speed'] ?? null,
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
                    'keys' => 'time,distance,heartrate,cadence,velocity_smooth,altitude,latlng,grade_smooth',
                    'key_by_type' => 'true',
                ])
                ->json();

            return is_array($streams) ? $streams : null;
        } catch (StravaRateLimitedException|StravaCircuitOpenException $e) {
            // The detail is already stored; re-queue the job rather than
            // silently drop the streams.
            throw $e;
        } catch (RequestException $e) {
            // Streams are best-effort: 404 (no streams, e.g. treadmill/manual)
            // and other 4xx/5xx alike just log and continue without them.
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
     * 404 (deleted) / 403 (unshared) etc. — permanent, no amount of retrying
     * recovers it, unlike a transient 5xx or transport error.
     */
    private function isPermanentClientError(?int $status): bool
    {
        return $status !== null && $status >= 400 && $status < 500;
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
     * Raise the athlete's max HR when a run beats it, and re-derive their zones.
     * The athlete's own peak can never be an underestimate, so it always wins.
     *
     * Assumes oldest-first ingest order: the profile climbs as ingest goes, so a
     * backfill re-zones later activities against the corrected ceiling.
     */
    private function reconcileMaxHeartRate(Activity $activity, ActivityDetail $detail): void
    {
        $observed = $detail->max_heartrate;
        if ($observed === null) {
            return;
        }

        $observed = (int) round((float) $observed);
        if (! HeartRateZones::isPlausibleMax($observed)) {
            return;
        }

        $user = $activity->user;
        $profile = $user->runnerProfile;

        // Most athletes have no profile row (one only exists after a manual edit
        // or a Strava sync with custom zones), so compare against the effective
        // default too — keying off the row alone would leave that majority
        // never corrected.
        $currentMax = $profile !== null ? $profile->max_hr : (int) config('runner.max_hr');
        if ($observed <= $currentMax) {
            return;
        }

        $restingHr = $profile !== null ? $profile->resting_hr : (int) config('runner.resting_hr');
        $zones = HeartRateZones::derive($observed, $restingHr);

        if ($profile === null) {
            $user->runnerProfile()->create([
                'source' => 'observed',
                'max_hr' => $observed,
                'resting_hr' => $restingHr,
                'hr_zones' => $zones,
                'optimal_cadence_spm' => (int) config('runner.optimal_cadence_spm'),
            ]);

            // The relation cached a null before the row existed.
            $user->unsetRelation('runnerProfile');

            return;
        }

        // Updating in place keeps the loaded relation current, so the zone read
        // that follows sees the new bands without another query.
        $profile->update([
            'max_hr' => $observed,
            'hr_zones' => $zones,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $streams
     */
    private function computeAndStoreSummary(Activity $activity, ActivityDetail $detail, ?array $streams): void
    {
        if ($streams === null) {
            return;
        }

        $this->reconcileMaxHeartRate($activity, $detail);

        // Not $detail->activity: during ingest the row is still a stub, and
        // AnalyzedScope would resolve that belongsTo to null.
        $profile = $activity->user->hrProfile();
        $hrZones = $profile['hr_zones'];
        $optimalCadence = $profile['optimal_cadence_spm'];

        $summary = $this->streamAnalysis->compute(
            $streams,
            $hrZones,
            is_array($detail->splits_metric) ? $detail->splits_metric : null,
            $optimalCadence,
            $detail->distance,
            $detail->laps(),
        );

        $minutesInZone = StreamSummary::fromArray($summary)->zoneMinutes();
        $trimp = $minutesInZone !== null ? $this->trainingLoad->edwardsTrimp($minutesInZone) : null;

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
     *
     * $rebuildAggregates is opt-out for batch callers only: the forward rebuild
     * is O(weeks-forward) per activity, so a whole-history loop must switch it
     * off and roll the snapshots once at the end instead.
     */
    public function recomputeSummary(Activity $activity, bool $rebuildAggregates = true): void
    {
        $detail = $activity->detail;
        $stream = $activity->stream;
        if ($detail === null || $stream === null || $stream->data === []) {
            return;
        }

        $this->computeAndStoreSummary($activity, $detail, $stream->data);

        if ($rebuildAggregates && $detail->start_date_local !== null) {
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
            // Best-effort (see class header): must never block ingest, or the
            // activity is left an un-ingestable stub forever.
            Log::warning('weather lookup failed (non-fatal)', [
                'activity_id' => $detail->activity_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($snapshot === null) {
            return;
        }

        $detail->update($snapshot->toActivityDetailAttributes());
    }

    private function handleDetailFailure(Activity $activity, Throwable $reason): void
    {
        $status = $this->httpStatus($reason);
        if ($this->isPermanentClientError($status)) {
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
