<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Services\Run\Metrics\VdotEstimator;
use App\Enums\RaceOutcome;
use App\Support\SharedPropCacheKey;
use Database\Factories\RaceGoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * A race the user is training for. "Goal" only in the DB/model layer — the
 * user-facing name is "Race".
 *
 * At most one row per user is active ({@see self::active()}, `completed_at`
 * null) at a time; history is retained rather than overwritten, so this is
 * enforced at the application layer (see `RaceController::store()`), not a
 * DB constraint.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $race_date
 * @property int $distance_m
 * @property int $goal_time_sec
 * @property string|null $name
 * @property Carbon|null $completed_at
 * @property RaceOutcome|null $outcome
 * @property int|null $outcome_activity_id
 * @property int|null $finish_time_sec
 * @property Carbon|null $outcome_recorded_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $outcome_asked_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'race_date',
    'distance_m',
    'goal_time_sec',
    'name',
    'completed_at',
    'outcome',
    'outcome_activity_id',
    'finish_time_sec',
    'outcome_recorded_at',
])]
class RaceGoal extends Model
{
    /** @use HasFactory<RaceGoalFactory> */
    use HasFactory;

    #[Override]
    protected static function booted(): void
    {
        $bust = function (RaceGoal $race): void {
            SharedPropCacheKey::ActiveRace->forget($race->user_id);
            app(ResolveActiveRaceAction::class)->forget($race->user_id);
            app(VdotEstimator::class)->forget($race->user);
        };

        static::saved($bust);
        static::deleted($bust);
    }

    /**
     * @param  Builder<RaceGoal>  $query
     * @return Builder<RaceGoal>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<RaceGoalChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(RaceGoalChange::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function outcomeActivity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'outcome_activity_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'race_date' => 'date:Y-m-d',
            'distance_m' => 'integer',
            'goal_time_sec' => 'integer',
            'completed_at' => 'datetime',
            'outcome' => RaceOutcome::class,
            'outcome_activity_id' => 'integer',
            'finish_time_sec' => 'integer',
            'outcome_recorded_at' => 'datetime',
            'reminded_at' => 'datetime',
            'outcome_asked_at' => 'datetime',
        ];
    }
}
