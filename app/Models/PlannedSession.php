<?php

declare(strict_types=1);

namespace App\Models;

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
 * around and never overwrite; the readiness clamp, volume redistribution and
 * segment structure ({@see \App\Services\Run\Plan\SegmentGenerator}) are all
 * render-time-only and never mutate this row (see
 * `docs/features/plan-periodizer.md`). `status`/`compliance_score`/
 * `ran_anyway` are the one exception — written once, by `plan:score-compliance`
 * (daily), the morning after a day passes; `skipped` is written earlier,
 * whenever the athlete explicitly excuses the day via `PlanController::update()`.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property PlanPhase $phase
 * @property SessionType $session_type
 * @property bool $pinned
 * @property bool $skipped
 * @property PlannedSessionStatus $status
 * @property int|null $compliance_score
 * @property bool $ran_anyway
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'date',
    'phase',
    'session_type',
    'pinned',
    'skipped',
    'status',
    'compliance_score',
    'ran_anyway',
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

    /**
     * Count PAST, SCORED `Rest` rows where nothing was logged that date
     * (`ran_anyway = false`) — "honored" per
     * {@see \App\Actions\Gamification\GrantSeasonUnlocksAction}'s and the
     * badge board's shared definition. `[$from, $to]` scopes to one season;
     * omitted, it's the lifetime count across the user's whole plan history.
     * A past row `plan:score-compliance` hasn't reached yet is excluded
     * (still `Planned`, not proven honored) rather than assumed honored —
     * the same "stays honestly pending, never guessed" default the AI
     * pipeline uses for its own unscored/paused states.
     */
    public static function restHonoredCountForUser(int $userId, Carbon $today, ?Carbon $from = null, ?Carbon $to = null): int
    {
        $rangeEnd = $today->copy()->subDay();
        if ($to !== null && $to->lessThan($rangeEnd)) {
            $rangeEnd = $to->copy();
        }
        if ($from !== null && $rangeEnd->lessThan($from)) {
            return 0;
        }

        $query = self::query()
            ->where('user_id', $userId)
            ->where('session_type', SessionType::Rest)
            ->where('status', '!=', PlannedSessionStatus::Planned)
            ->where('ran_anyway', false)
            ->where('date', '<=', $rangeEnd->toDateString());
        if ($from !== null) {
            $query->where('date', '>=', $from->toDateString());
        }

        return $query->count();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'date' => 'date:Y-m-d',
            'phase' => PlanPhase::class,
            'session_type' => SessionType::class,
            'pinned' => 'boolean',
            'skipped' => 'boolean',
            'status' => PlannedSessionStatus::class,
            'compliance_score' => 'integer',
            'ran_anyway' => 'boolean',
        ];
    }
}
