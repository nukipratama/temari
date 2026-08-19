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

    /**
     * Count PAST `Rest` rows with no `Activity` logged that date — "honored"
     * per {@see \App\Actions\Gamification\GrantSeasonUnlocksAction}'s and
     * the badge board's shared definition. `[$from, $to]` scopes to one
     * season; omitted, it's the lifetime count across the user's whole plan
     * history.
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
            ->where('date', '<=', $rangeEnd->toDateString());
        if ($from !== null) {
            $query->where('date', '>=', $from->toDateString());
        }
        $restDates = $query->pluck('date');

        if ($restDates->isEmpty()) {
            return 0;
        }

        $activityDates = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $userId)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [
                $restDates->min()->copy()->startOfDay(),
                $restDates->max()->copy()->endOfDay(),
            ])
            ->selectRaw('DISTINCT DATE(activity_details.start_date_local) as d')
            ->pluck('d')
            ->map(fn (string $d): string => Carbon::parse($d)->toDateString())
            ->flip();

        return $restDates->filter(fn (Carbon $date): bool => ! isset($activityDates[$date->toDateString()]))->count();
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
            'distance_band' => DistanceBand::class,
            'pace_band' => PaceBand::class,
            'pinned' => 'boolean',
            'status' => PlannedSessionStatus::class,
        ];
    }
}
