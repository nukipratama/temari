<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Support\Numeric;
use Illuminate\Support\Facades\DB;

/**
 * Turns `/athlete/activities` summary payloads into visible {@see Activity} rows
 * backed by a summary-only {@see ActivityDetail}. Costs no extra Strava reads:
 * the caller already paid for the page these payloads came from.
 */
class SummaryIngest
{
    /**
     * Rows per bulk statement, so a multi-year first-connect backfill does not
     * build a single statement past the driver's placeholder limit.
     */
    private const int CHUNK = 100;

    /**
     * ActivityDetail columns the summary endpoint can fill. Everything else on
     * the table (splits, laps, calories, device, weather, stream summary, TRIMP)
     * needs the detail or streams endpoint and stays null until hydration.
     *
     * @var list<string>
     */
    private const array SUMMARY_COLUMNS = [
        'name',
        'start_date_local',
        'start_date_utc',
        'distance',
        'moving_time',
        'elapsed_time',
        'average_speed',
        'max_speed',
        'total_elevation_gain',
        'elev_high',
        'elev_low',
        'has_heartrate',
        'average_heartrate',
        'max_heartrate',
        'average_cadence',
        'workout_type',
        'summary_polyline',
        'start_lat',
        'start_lng',
    ];

    /**
     * @param  list<array<string, mixed>>  $summaries  oldest-first
     * @return int newly inserted activity rows
     */
    public function store(int $userId, array $summaries): int
    {
        if ($summaries === []) {
            return 0;
        }

        $byExternalId = [];
        foreach ($summaries as $summary) {
            $externalId = Numeric::int($summary['id'] ?? 0);
            if ($externalId > 0) {
                $byExternalId[$externalId] = $summary;
            }
        }

        if ($byExternalId === []) {
            return 0;
        }

        $inserted = $this->insertActivityRows($userId, array_keys($byExternalId));

        $activities = Activity::query()
            ->withStubs()
            ->summaryOnly()
            ->where('user_id', $userId)
            ->whereIn('strava_external_id', array_keys($byExternalId))
            ->get(['id', 'strava_external_id']);

        if ($activities->isEmpty()) {
            return $inserted;
        }

        $detailRows = [];
        foreach ($activities as $activity) {
            $summary = $byExternalId[$activity->strava_external_id] ?? null;
            if ($summary !== null) {
                $detailRows[] = ['activity_id' => $activity->id, ...StravaDetailMapper::summary($summary)];
            }
        }

        foreach (array_chunk($detailRows, self::CHUNK) as $chunk) {
            ActivityDetail::query()->upsert($chunk, ['activity_id'], self::SUMMARY_COLUMNS);
        }

        // A stub left behind by an earlier failed ingest now carries honest
        // summary data, so it earns its way past the AnalyzedScope.
        Activity::query()
            ->withStubs()
            ->whereIn('id', $activities->pluck('id'))
            ->whereNull('analyzed_at')
            ->update(['analyzed_at' => now()]);

        return $inserted;
    }

    /**
     * @param  list<int>  $externalIds
     */
    private function insertActivityRows(int $userId, array $externalIds): int
    {
        $now = now();
        $rows = array_map(fn (int $id): array => [
            'user_id' => $userId,
            'strava_external_id' => $id,
            'ingest_state' => IngestState::Summary->value,
            'fetched_at' => $now,
            'analyzed_at' => $now,
            'detail_fail_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $externalIds);

        return DB::transaction(function () use ($rows): int {
            $inserted = 0;
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $inserted += (int) Activity::query()->insertOrIgnore($chunk);
            }

            return $inserted;
        });
    }
}
