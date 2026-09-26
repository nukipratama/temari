<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Run\Plan\ResolvePlannedSessionsAction;
use App\Enums\IntentVerdict;
use App\Enums\PlanPhase;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Services\Run\Story\PastYouTrendBuilder;
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
 * `volume_multiplier` is the one number generation stamps rather than leaves
 * to render: it is the week's position in its season-long arc, which a render
 * window that reaches back only a few weeks cannot see. Fitness still enters
 * fresh at render — {@see \App\Services\Run\Plan\TrainingBaseline} is what the
 * multiplier scales. See `docs/decisions/the-arc-is-anchored-once.md`.
 *
 * `race_distance_m` is set only on a {@see SessionType::Race} row, and is what
 * keeps race day self-describing: `plan:close-finished-races` retires the
 * {@see RaceGoal} at 00:02, before `plan:score-compliance` grades the day, so a
 * race read back from the goal alone would already be gone by the time anything
 * needed its distance.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $date
 * @property PlanPhase $phase
 * @property SessionType $session_type
 * @property float|null $prescribed_km
 * @property float|null $clamped_km
 * @property int|null $eased_pace_sec_per_km
 * @property float|null $volume_multiplier
 * @property int|null $race_distance_m
 * @property int|null $prescribed_hard_minutes
 * @property PaceBand|null $prescribed_pace_band
 * @property int|null $prescribed_pace_sec_per_km
 * @property string|null $prescription_reason
 * @property array<string, int|float|string>|null $prescription_race_context
 * @property bool $pinned
 * @property bool $skipped
 * @property PlannedSessionStatus $status
 * @property int|null $compliance_score
 * @property int|null $distance_score
 * @property IntentVerdict|null $intent_verdict
 * @property array<string, int|float|string>|null $intent_evidence
 * @property bool $ran_anyway
 * @property Carbon|null $rest_clamped_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'date',
    'phase',
    'session_type',
    'race_distance_m',
    'prescribed_hard_minutes',
    'prescribed_pace_band',
    'prescribed_pace_sec_per_km',
    'prescription_reason',
    'prescription_race_context',
    'pinned',
    'skipped',
    'status',
    'compliance_score',
    'distance_score',
    'intent_verdict',
    'intent_evidence',
    'prescribed_km',
    'clamped_km',
    'eased_pace_sec_per_km',
    'volume_multiplier',
    'ran_anyway',
    'rest_clamped_at',
])]
class PlannedSession extends Model
{
    /** @use HasFactory<PlannedSessionFactory> */
    use HasFactory;

    public const array WORKOUT_TRANSFER_FIELDS = [
        'session_type',
        'skipped',
        'prescribed_hard_minutes',
        'prescribed_pace_band',
        'prescribed_pace_sec_per_km',
        'prescription_reason',
        'prescription_race_context',
        'race_distance_m',
    ];

    #[Override]
    protected static function booted(): void
    {
        $bust = static function (PlannedSession $row): void {
            app(ResolvePlannedSessionsAction::class)->forget($row->user_id);
        };

        static::saved(static function (PlannedSession $row) use ($bust): void {
            $bust($row);
            if ($row->wasRecentlyCreated || $row->wasChanged(['date', 'session_type', 'skipped', 'rest_clamped_at'])) {
                PastYouTrendBuilder::clearCacheForUserId($row->user_id);
            }
        });
        static::deleted(static function (PlannedSession $row) use ($bust): void {
            $bust($row);
            PastYouTrendBuilder::clearCacheForUserId($row->user_id);
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Count PAST, SCORED `Rest` rows where nothing was logged that date
     * (`ran_anyway = false`) — the badge board's "honored" definition.
     * `[$from, $to]` scopes to one season;
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

    /**
     * Whether this day is exempt from being graded: the athlete excused it
     * ahead of time, or the readiness clamp downgraded it to a full rest and
     * {@see \App\Services\Run\Plan\RestClampRecorder} recorded that. Both
     * resolve to {@see PlannedSessionStatus::Skip} — uncredited, but never
     * counted against the week's adherence, since neither is a day the
     * athlete failed to turn up for.
     */
    public function isExcused(): bool
    {
        return $this->skipped || $this->rest_clamped_at !== null;
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
            'race_distance_m' => 'integer',
            'prescribed_hard_minutes' => 'integer',
            'prescribed_pace_band' => PaceBand::class,
            'prescribed_pace_sec_per_km' => 'integer',
            'prescription_reason' => 'string',
            'prescription_race_context' => 'array',
            'pinned' => 'boolean',
            'skipped' => 'boolean',
            'status' => PlannedSessionStatus::class,
            'compliance_score' => 'integer',
            'distance_score' => 'integer',
            'intent_verdict' => IntentVerdict::class,
            'intent_evidence' => 'array',
            'prescribed_km' => 'float',
            'clamped_km' => 'float',
            'eased_pace_sec_per_km' => 'integer',
            'volume_multiplier' => 'float',
            'ran_anyway' => 'boolean',
            'rest_clamped_at' => 'datetime',
        ];
    }
}
