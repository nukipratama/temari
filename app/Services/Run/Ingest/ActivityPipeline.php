<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Services\Strava\StravaClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Per-activity ingest. Idempotent: re-running on an already-analyzed activity
 * is safe and refreshes the stored detail+stream.
 *
 * v1 scope stops at storing raw detail+stream. The compute pipeline
 * (TRIMP, stream summary, PRs, weather, vibe, run card, story line) layers
 * on top in Phase 3 — see `App\Services\Run\Metrics\*` and Story services.
 */
class ActivityPipeline
{
    private const int DETAIL_FETCH_MAX_ATTEMPTS = 5;

    public function __construct(private readonly StravaClient $client)
    {
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
            $this->handleDetailFailure($activity, new \RuntimeException('Strava returned non-array detail'));

            return;
        }

        $this->storeDetail($activity, $detail);

        // Streams are best-effort. Some old activities have no stream data,
        // others 404. Either way the run is still useful with detail alone.
        try {
            $streams = $this->client
                ->get($connection, "/activities/{$activity->strava_external_id}/streams", [
                    'keys' => 'time,distance,heartrate,cadence,velocity_smooth,altitude,latlng',
                    'key_by_type' => 'true',
                ])
                ->json();

            if (is_array($streams)) {
                $this->storeStreams($activity, $streams);
            }
        } catch (Throwable $e) {
            Log::info('streams fetch failed (non-fatal)', [
                'activity_id' => $activity->id,
                'error' => $e->getMessage(),
            ]);
        }

        $activity->update([
            'analyzed_at' => now(),
            'detail_fail_count' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function storeDetail(Activity $activity, array $detail): void
    {
        $start = $detail['start_date_local'] ?? $detail['start_date'] ?? null;

        ActivityDetail::query()->updateOrCreate(
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
     * @param  array<string, mixed>  $streams
     */
    private function storeStreams(Activity $activity, array $streams): void
    {
        ActivityStream::query()->updateOrCreate(
            ['activity_id' => $activity->id],
            ['data' => $streams],
        );
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
