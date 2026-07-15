<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Run\Metrics\PaceCalculator;
use Database\Factories\ActivityDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $activity_id
 * @property string|null $name
 * @property Carbon|null $start_date_local
 * @property float|null $distance
 * @property int|null $moving_time
 * @property int|null $elapsed_time
 * @property float|null $average_speed
 * @property float|null $total_elevation_gain
 * @property bool $has_heartrate
 * @property float|null $average_heartrate
 * @property int|null $max_heartrate
 * @property float|null $average_cadence
 * @property float|null $calories
 * @property array<int, array<string, mixed>>|null $splits_metric
 * @property string|null $summary_polyline
 * @property float|null $trimp_edwards
 * @property int|null $suffer_score
 * @property int|null $workout_type
 * @property float|null $elev_high
 * @property float|null $elev_low
 * @property string|null $device_name
 * @property float|null $average_watts
 * @property float|null $max_speed
 * @property array<string, mixed>|null $stream_summary
 * @property int|null $weather_temp_c
 * @property int|null $weather_humidity_pct
 * @property bool|null $weather_rain_detected
 * @property int|null $weather_wind_speed_kmh
 * @property int|null $weather_wind_gust_kmh
 * @property int|null $weather_wind_direction_deg
 * @property bool|null $weather_rain_is_forecast
 * @property float|null $start_lat
 * @property float|null $start_lng
 * @property string|null $location_name
 * @property string|null $location_country
 * @property Carbon|null $location_resolved_at
 * @property string|null $vibe_state
 * @property-read Activity $activity
 */
#[Fillable([
    'activity_id',
    'name',
    'start_date_local',
    'distance',
    'moving_time',
    'elapsed_time',
    'average_speed',
    'total_elevation_gain',
    'has_heartrate',
    'average_heartrate',
    'max_heartrate',
    'average_cadence',
    'calories',
    'splits_metric',
    'summary_polyline',
    'trimp_edwards',
    'suffer_score',
    'workout_type',
    'elev_high',
    'elev_low',
    'device_name',
    'average_watts',
    'max_speed',
    'stream_summary',
    'weather_temp_c',
    'weather_humidity_pct',
    'weather_rain_detected',
    'weather_wind_speed_kmh',
    'weather_wind_gust_kmh',
    'weather_wind_direction_deg',
    'weather_rain_is_forecast',
    'start_lat',
    'start_lng',
    'location_name',
    'location_country',
    'location_resolved_at',
    'vibe_state',
])]
class ActivityDetail extends Model
{
    /** @use HasFactory<ActivityDetailFactory> */
    use HasFactory;

    /**
     * Detail rows owned by the given user (i.e. whose activity belongs to them).
     *
     * @param  Builder<ActivityDetail>  $query
     * @return Builder<ActivityDetail>
     */
    #[Scope]
    protected function forUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('activity', fn ($q) => $q->where('user_id', $userId));
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * Pace in seconds per kilometre. Null when distance or time is missing/zero.
     */
    public function paceSecPerKm(): ?float
    {
        return PaceCalculator::secPerKm($this->distance, $this->moving_time);
    }

    /**
     * Returns the stream_summary array, or an empty array when the column is null.
     * The column is JSON-cast, so null is the only non-array value possible.
     *
     * @return array<string, mixed>
     */
    public function streamSummary(): array
    {
        return $this->stream_summary ?? [];
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'start_date_local' => 'datetime:Y-m-d\TH:i:s',
            'distance' => 'float',
            'moving_time' => 'integer',
            'elapsed_time' => 'integer',
            'average_speed' => 'float',
            'total_elevation_gain' => 'float',
            'has_heartrate' => 'boolean',
            'average_heartrate' => 'float',
            'max_heartrate' => 'integer',
            'average_cadence' => 'float',
            'calories' => 'float',
            'splits_metric' => 'array',
            'trimp_edwards' => 'float',
            'suffer_score' => 'integer',
            'workout_type' => 'integer',
            'elev_high' => 'float',
            'elev_low' => 'float',
            'average_watts' => 'float',
            'max_speed' => 'float',
            'stream_summary' => 'array',
            'weather_temp_c' => 'integer',
            'weather_humidity_pct' => 'integer',
            'weather_rain_detected' => 'boolean',
            'weather_wind_speed_kmh' => 'integer',
            'weather_wind_gust_kmh' => 'integer',
            'weather_wind_direction_deg' => 'integer',
            'weather_rain_is_forecast' => 'boolean',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'location_resolved_at' => 'datetime',
        ];
    }
}
