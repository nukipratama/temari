<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\Run\Plan\ResolvePlannedSessionsAction;
use App\Enums\FallOffTilt;
use App\Enums\IntentVerdict;
use App\Enums\PlanPhase;
use App\Enums\PaceBand;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Enums\TimeTrialOutcome;
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
 * around and never overwrite; volume redistribution and segment structure
 * ({@see \App\Services\Run\Plan\SegmentGenerator}) are render-time-only and
 * never stored, but the readiness clamp outcome (`clamped_km`/
 * `eased_pace_sec_per_km`) and its assessment are persisted by
 * {@see \App\Services\Run\Plan\RestClampRecorder} (see
 * `docs/features/plan-periodizer.md`). `status`/`compliance_score`/
 * `ran_anyway` are written once, by `plan:score-compliance`
 * (daily), the morning after a day passes; `skipped` is written earlier,
 * whenever the athlete explicitly excuses the day via `PlanController::update()`.
 *
 * `volume_multiplier` is the one number generation stamps rather than leaves
 * to render: it is the week's position in its season-long arc, which a render
 * window that reaches back only a few weeks cannot see. Fitness still enters
 * fresh at render — {@see \App\Services\Run\Plan\TrainingBaseline} is what the
 * multiplier scales. See `docs/decisions/the-arc-is-anchored-once.md`.
 *
 * A make-up move stamps the day it empties with `made_up_on`, the date its
 * session moved to, and points that day back at it through `made_up_from_id`.
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
 * @property array<string, mixed>|null $readiness_assessment
 * @property float|null $volume_multiplier
 * @property int|null $race_distance_m
 * @property int|null $prescribed_hard_minutes
 * @property PaceBand|null $prescribed_pace_band
 * @property int|null $prescribed_pace_sec_per_km
 * @property string|null $prescription_reason
 * @property array<string, int|float|string>|null $prescription_race_context
 * @property FallOffTilt|null $fall_off_tilt
 * @property TimeTrialOutcome|null $time_trial_outcome
 * @property bool $pinned
 * @property bool $skipped
 * @property Carbon|null $made_up_on
 * @property int|null $made_up_from_id
 * @property PlannedSessionStatus $status
 * @property int|null $compliance_score
 * @property int|null $distance_score
 * @property IntentVerdict|null $intent_verdict
 * @property array<string, int|float|string>|null $intent_evidence
 * @property bool $ran_anyway
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
    'fall_off_tilt',
    'time_trial_outcome',
    'pinned',
    'skipped',
    'made_up_on',
    'made_up_from_id',
    'status',
    'compliance_score',
    'distance_score',
    'intent_verdict',
    'intent_evidence',
    'prescribed_km',
    'clamped_km',
    'eased_pace_sec_per_km',
    'readiness_assessment',
    'volume_multiplier',
    'ran_anyway',
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
        'fall_off_tilt',
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
            if ($row->wasRecentlyCreated || $row->wasChanged(['date', 'session_type', 'skipped'])) {
                PastYouTrendBuilder::clearCacheForUserId($row->user_id);
            }
        });
        static::deleted(static function (PlannedSession $row): void {
            self::forgetCachedReads($row->user_id);
        });
    }

    public static function forgetCachedReads(int $userId): void
    {
        app(ResolvePlannedSessionsAction::class)->forget($userId);
        PastYouTrendBuilder::clearCacheForUserId($userId);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether this day is exempt from being graded: the athlete excused it
     * ahead of time. It resolves to {@see PlannedSessionStatus::Skip} —
     * uncredited, but never counted against the week's adherence, since it is
     * not a day the athlete failed to turn up for.
     */
    public function isExcused(): bool
    {
        return $this->skipped === true;
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
            'fall_off_tilt' => FallOffTilt::class,
            'time_trial_outcome' => TimeTrialOutcome::class,
            'pinned' => 'boolean',
            'skipped' => 'boolean',
            'made_up_on' => 'date:Y-m-d',
            'made_up_from_id' => 'integer',
            'status' => PlannedSessionStatus::class,
            'compliance_score' => 'integer',
            'distance_score' => 'integer',
            'intent_verdict' => IntentVerdict::class,
            'intent_evidence' => 'array',
            'prescribed_km' => 'float',
            'clamped_km' => 'float',
            'eased_pace_sec_per_km' => 'integer',
            'readiness_assessment' => 'array',
            'volume_multiplier' => 'float',
            'ran_anyway' => 'boolean',
        ];
    }
}
