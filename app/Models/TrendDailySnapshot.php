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
 * One row per user per day, written once by {@see \App\Services\Run\Trend\TrendSnapshotWriter}
 * and never updated afterwards — the history is grow-forward only, with no
 * retroactive backfill. A day with no row simply has no history yet.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $snapshot_date
 * @property float|null $vdot
 * @property float|null $pace_variability_sec
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'snapshot_date',
    'vdot',
    'pace_variability_sec',
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
        ];
    }
}
