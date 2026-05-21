<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\StravaConnection;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use App\Services\AI\AnalysisService;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Gamification\MilestoneDetector;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Story\RunCardFactory;
use App\Services\Run\Story\Temari;
use App\Services\Strava\StravaClient;
use App\Services\Weather\OpenMeteoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

// Idempotent: re-running refreshes all artifacts. Each compute step is
// best-effort — one failure (no HR stream, weather API down) doesn't
// block the others.
class ActivityPipeline
{
    private const int DETAIL_FETCH_MAX_ATTEMPTS = 5;

    private const string BACKFILL_SLOT_CACHE_PREFIX = 'ai.backfill.next-slot:';

    private const int BACKFILL_SLOT_CACHE_TTL_HOURS = 2;

    public function __construct(
        private readonly StravaClient $client,
        private readonly StreamAnalysis $streamAnalysis,
        private readonly TrainingLoad $trainingLoad,
        private readonly PersonalRecords $personalRecords,
        private readonly OpenMeteoClient $weather,
        private readonly RunCardFactory $cardFactory,
        private readonly Temari $temari,
        private readonly AnalysisService $analysisService,
        private readonly WeeklyAggregator $weeklyAggregator,
        private readonly MilestoneDetector $milestoneDetector,
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
        $newPrCategories = $this->personalRecords->detectAndStore($activity, $detailModel);

        // Story layer must run after PR detection — Temari mood reads PR rows.
        $this->cardFactory->build($activity, $detailModel);
        $this->temari->postRunLine($activity, $detailModel);
        $this->milestoneDetector->detect($activity, $detailModel, $newPrCategories);
        $this->cascadeAfterIngest($activity, $detailModel);

        $activity->update([
            'analyzed_at' => now(),
            'detail_fail_count' => 0,
        ]);
    }

    private function cascadeAfterIngest(Activity $activity, ActivityDetail $detail): void
    {
        $user = $activity->user;
        $today = Carbon::today()->toDateString();
        $delaySec = $this->backfillDelaySeconds($activity, $detail);

        $this->analysisService->requestActivityGroup($activity, delaySeconds: $delaySec);
        $this->analysisService->requestBriefingGroup($user, $today, invalidate: true, delaySeconds: $delaySec);
        $this->analysisService->request(
            subjectOrType: AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::DailyGreeting,
            discriminator: $today,
            delaySeconds: $delaySec,
            invalidate: true,
        );
        $this->analysisService->request(
            subjectOrType: AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::TrendCaption,
            discriminator: $today,
            delaySeconds: $delaySec,
            invalidate: true,
        );

        if ($detail->start_date_local === null) {
            return;
        }
        $snapshot = $this->weeklyAggregator->rebuildForWeekOf($user, $detail->start_date_local);
        if ($snapshot !== null) {
            $this->analysisService->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: $snapshot->id,
                type: AnalysisType::WeeklyRecap,
                delaySeconds: $delaySec,
                invalidate: true,
            );
        }
    }

    /**
     * Activities started more than `ai.backfill_threshold_hours` ago are
     * treated as backfill — their cascade gets staggered behind any other
     * backfilled cascades queued in the last 2 hours for this user. Fresh
     * runs (or activities with no start timestamp) bypass the cache and
     * dispatch immediately at delay=0.
     */
    private function backfillDelaySeconds(Activity $activity, ActivityDetail $detail): int
    {
        $startedAt = $detail->start_date_local;
        if ($startedAt === null) {
            return 0;
        }

        $thresholdHours = (int) config('ai.backfill_threshold_hours', 24);
        if (Carbon::now()->diffInHours($startedAt, absolute: true) < $thresholdHours) {
            return 0;
        }

        $staggerSec = max(1, (int) config('ai.backfill_stagger_seconds', 360));
        $key = self::BACKFILL_SLOT_CACHE_PREFIX.$activity->user_id;
        $now = Carbon::now();

        $cached = Cache::get($key);
        $slotAt = ($cached instanceof Carbon && $cached->gt($now)) ? $cached : $now->copy();
        $delaySec = (int) $now->diffInSeconds($slotAt, absolute: true);

        Cache::put($key, $slotAt->copy()->addSeconds($staggerSec), $now->copy()->addHours(self::BACKFILL_SLOT_CACHE_TTL_HOURS));

        if ($delaySec > 0) {
            Log::info('ai.backfill.queued', [
                'activity_id' => $activity->id,
                'user_id' => $activity->user_id,
                'delay_sec' => $delaySec,
                'slot_at' => $slotAt->toIso8601String(),
            ]);
        }

        return $delaySec;
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
