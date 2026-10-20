<?php

declare(strict_types=1);

namespace App\Services\Run\Ingest;

use App\Support\Numeric;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Maps a Strava activity payload onto {@see \App\Models\ActivityDetail} columns:
 * the summary endpoint's fields, plus the detail endpoint's extras.
 */
final class StravaDetailMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function summary(array $payload): array
    {
        $local = $payload['start_date_local'] ?? null;
        $start = is_string($local) && $local !== '' ? $local : ($payload['start_date'] ?? null);
        // start_latlng is null/empty for non-GPS activities (treadmill, manual).
        $latlng = is_array($payload['start_latlng'] ?? null) && count($payload['start_latlng']) === 2
            ? $payload['start_latlng']
            : null;

        return [
            'name' => $payload['name'] ?? null,
            'start_date_local' => is_string($start) && $start !== '' ? Carbon::parse($start)->toDateTimeString() : null,
            'start_date_utc' => is_string($payload['start_date'] ?? null) && $payload['start_date'] !== '' ? Carbon::parse($payload['start_date'])->utc()->toDateTimeString() : null,
            'distance' => $payload['distance'] ?? null,
            'moving_time' => $payload['moving_time'] ?? null,
            'elapsed_time' => $payload['elapsed_time'] ?? null,
            'average_speed' => $payload['average_speed'] ?? null,
            'max_speed' => $payload['max_speed'] ?? null,
            'total_elevation_gain' => $payload['total_elevation_gain'] ?? null,
            'elev_high' => $payload['elev_high'] ?? null,
            'elev_low' => $payload['elev_low'] ?? null,
            'has_heartrate' => (bool) ($payload['has_heartrate'] ?? false),
            'average_heartrate' => $payload['average_heartrate'] ?? null,
            'max_heartrate' => $payload['max_heartrate'] ?? null,
            'average_cadence' => $payload['average_cadence'] ?? null,
            'workout_type' => $payload['workout_type'] ?? null,
            'summary_polyline' => Arr::get($payload, 'map.summary_polyline'),
            'start_lat' => $latlng === null ? null : Numeric::float($latlng[0]),
            'start_lng' => $latlng === null ? null : Numeric::float($latlng[1]),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function detail(array $payload): array
    {
        return [
            ...self::summary($payload),
            'calories' => $payload['calories'] ?? null,
            'splits_metric' => $payload['splits_metric'] ?? null,
            'laps' => $payload['laps'] ?? null,
            'suffer_score' => $payload['suffer_score'] ?? null,
            'device_name' => $payload['device_name'] ?? null,
            'average_watts' => $payload['average_watts'] ?? null,
        ];
    }
}
