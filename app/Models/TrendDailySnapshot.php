<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrendDailySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * One row per user per day, recomputed by {@see \App\Services\Run\Trend\TrendSnapshotWriter}
 * whenever analyzed activity or closed-day reconciliation changes its evidence.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $snapshot_date
 * @property float|null $vdot
 * @property float|null $pace_variability_sec
 * @property int|null $race_goal_id
 * @property int|null $supported_time_sec
 * @property int|null $supported_source_distance_m
 * @property Carbon|null $supported_source_date
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'snapshot_date',
    'vdot',
    'pace_variability_sec',
    'race_goal_id',
    'supported_time_sec',
    'supported_source_distance_m',
    'supported_source_date',
])]
class TrendDailySnapshot extends Model
{
    /** @use HasFactory<TrendDailySnapshotFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'snapshot_date' => 'date:Y-m-d',
            'vdot' => 'float',
            'pace_variability_sec' => 'float',
            'race_goal_id' => 'integer',
            'supported_time_sec' => 'integer',
            'supported_source_distance_m' => 'integer',
            'supported_source_date' => 'date:Y-m-d',
        ];
    }
}
