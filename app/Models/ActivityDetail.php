<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ActivityDetailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property array<string, mixed>|null $stream_summary
 * @property int|null $weather_temp_c
 * @property int|null $weather_humidity_pct
 * @property bool|null $weather_rain_detected
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
    'stream_summary',
    'weather_temp_c',
    'weather_humidity_pct',
    'weather_rain_detected',
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
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'start_date_local' => 'datetime',
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
            'stream_summary' => 'array',
            'weather_temp_c' => 'integer',
            'weather_humidity_pct' => 'integer',
            'weather_rain_detected' => 'boolean',
            'start_lat' => 'float',
            'start_lng' => 'float',
            'location_resolved_at' => 'datetime',
        ];
    }
}
