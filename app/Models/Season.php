<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\AI\Analysis;
use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * A 12-week (self-scaled) or race-to-race (race-oriented) training arc — the
 * unit the badge board's "this season" counts scope against. Auto-cycled by
 * {@see \App\Services\Run\Plan\SeasonService::ensureCurrent()}, the same
 * "mode switch takes effect at the next call" rule {@see
 * \App\Services\Run\Plan\Periodizer} already follows.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $race_goal_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property-read User $user
 * @property-read RaceGoal|null $raceGoal
 */
#[Fillable(['user_id', 'race_goal_id', 'starts_at', 'ends_at'])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<RaceGoal, $this>
     */
    public function raceGoal(): BelongsTo
    {
        return $this->belongsTo(RaceGoal::class);
    }

    /**
     * @return HasMany<SeasonGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(SeasonGoal::class);
    }

    /**
     * @return MorphMany<Analysis, $this>
     */
    public function analyses(): MorphMany
    {
        return $this->morphMany(Analysis::class, 'subject');
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'race_goal_id' => 'integer',
            'starts_at' => 'date:Y-m-d',
            'ends_at' => 'date:Y-m-d',
        ];
    }
}
