<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\AI\Analysis;
use App\Actions\Run\Plan\ResolveSeasonAction;
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
 * `starts_at` is also the arc's own origin: every phase and volume
 * multiplier is counted from the Monday of this date, not from whichever
 * week happens to be current — see
 * `docs/decisions/the-arc-is-anchored-once.md`. `anchor_weekly_volume_km`
 * is the trailing weekly volume as it stood then, frozen so the ramp has
 * something fixed to ramp off, and moved mid-season only by
 * {@see \App\Services\Run\Plan\SeasonService::reanchorIfCollapsed()} — downward,
 * past a sustained collapse. Null on a season created before that decision
 * (and on a factory row); {@see \App\Services\Run\Plan\TrainingBaseline}
 * falls back to the live trailing mean there.
 *
 * `opens_with_recovery` marks a self-scaled arc that follows a race the
 * athlete has actually run: its first week is a recovery week rather than the
 * cycle's usual Build. Frozen at creation for the same reason the anchor is —
 * the season chain behind it may change, the arc it already prescribed may
 * not. See `docs/decisions/a-closed-race-earns-a-recovery-week.md`.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $race_goal_id
 * @property float|null $anchor_weekly_volume_km
 * @property bool $opens_with_recovery
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property-read User $user
 * @property-read RaceGoal|null $raceGoal
 */
#[Fillable(['user_id', 'race_goal_id', 'anchor_weekly_volume_km', 'opens_with_recovery', 'starts_at', 'ends_at'])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    #[Override]
    protected static function booted(): void
    {
        $bust = function (Season $row): void {
            app(ResolveSeasonAction::class)->forget($row->user_id);
        };

        static::saved($bust);
        static::deleted($bust);
    }

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
            'anchor_weekly_volume_km' => 'float',
            'opens_with_recovery' => 'boolean',
            'starts_at' => 'date:Y-m-d',
            'ends_at' => 'date:Y-m-d',
        ];
    }
}
