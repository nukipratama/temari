<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdaptationReason;
use Database\Factories\PlanAdaptationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * What the periodizer decided to do about one week, and why
 * ({@see \App\Services\Run\Plan\PlanAdapter}). Written once per
 * regeneration, keyed `unique(user_id, week_start)`, so the Plan tab can
 * explain a deload that a later readiness recovery would otherwise make
 * look unmotivated.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $week_start
 * @property AdaptationReason $reason
 * @property bool $deload
 * @property int $quality_delta
 * @property int $adherence_pct
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'week_start',
    'reason',
    'deload',
    'quality_delta',
    'adherence_pct',
])]
class PlanAdaptation extends Model
{
    /** @use HasFactory<PlanAdaptationFactory> */
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
            'user_id' => 'integer',
            'week_start' => 'date:Y-m-d',
            'reason' => AdaptationReason::class,
            'deload' => 'boolean',
            'quality_delta' => 'integer',
            'adherence_pct' => 'integer',
        ];
    }
}
