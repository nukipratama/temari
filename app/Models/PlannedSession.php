<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DistanceBand;
use App\Enums\PaceBand;
use App\Enums\PlanPhase;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use Database\Factories\PlannedSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * One day of a user's periodized plan ({@see \App\Services\Run\Plan\Periodizer}).
 * `unique(user_id, date)` — the app's first clean single-purpose daily-grain
 * unique table. A `pinned` row is a fixed constraint the periodizer must plan
 * around and never overwrite; the readiness clamp and volume redistribution
 * are both render-time-only and never mutate this row (see
 * `docs/features/plan-periodizer.md`).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property PlanPhase $phase
 * @property SessionType $session_type
 * @property DistanceBand $distance_band
 * @property PaceBand|null $pace_band
 * @property bool $pinned
 * @property PlannedSessionStatus $status
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'date',
    'phase',
    'session_type',
    'distance_band',
    'pace_band',
    'pinned',
    'status',
])]
class PlannedSession extends Model
{
    /** @use HasFactory<PlannedSessionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'phase' => PlanPhase::class,
            'session_type' => SessionType::class,
            'distance_band' => DistanceBand::class,
            'pace_band' => PaceBand::class,
            'pinned' => 'boolean',
            'status' => PlannedSessionStatus::class,
        ];
    }
}
