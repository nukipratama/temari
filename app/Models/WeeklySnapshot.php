<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WeeklySnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $week_ending
 * @property float|null $distance_km
 * @property int|null $runs
 * @property int|null $moving_time_sec
 * @property float|null $weekly_trimp
 * @property float|null $atl_7d
 * @property float|null $ctl_42d
 * @property float|null $form
 * @property string|null $form_status
 * @property float|null $avg_decoupling
 * @property float|null $monotony
 * @property float|null $strain
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'week_ending',
    'distance_km',
    'runs',
    'moving_time_sec',
    'weekly_trimp',
    'atl_7d',
    'ctl_42d',
    'form',
    'form_status',
    'avg_decoupling',
    'monotony',
    'strain',
])]
class WeeklySnapshot extends Model
{
    /** @use HasFactory<WeeklySnapshotFactory> */
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
            'week_ending' => 'date',
            'distance_km' => 'float',
            'runs' => 'integer',
            'moving_time_sec' => 'integer',
            'weekly_trimp' => 'float',
            'atl_7d' => 'float',
            'ctl_42d' => 'float',
            'form' => 'float',
            'avg_decoupling' => 'float',
            'monotony' => 'float',
            'strain' => 'float',
        ];
    }
}
