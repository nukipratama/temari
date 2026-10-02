<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\AdaptationReason;
use App\Enums\IntentVerdict;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\AI\HydrationBacklog;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Decides what the periodizer should do differently this week given what
 * actually happened last week, what the load numbers say, and how the race
 * projection compares to the goal time. Rules own every number, the same as
 * the rest of the plan engine.
 *
 * Priority is safety first: a deload trigger wins over adherence, and both
 * win over race-pace feedback, so chasing a goal time can never talk the
 * plan past a red flag. The race-pace arm is the only one that moves
 * prescribed work in either direction; the rest only ever reduce.
 */
final readonly class PlanAdapter
{
    /** Below this share of the week's prescription completed, last week counts as a re-entry, not a catch-up. */
    public const float MISSED_WEEK_ADHERENCE = 0.50;

    /** Share of an easy day's time above Z2 that stops it being an easy day. */
    public const float EASY_DAY_HARD_SHARE = 0.20;

    /** One ragged day is a bad morning. This many is how the week was run. */
    public const int RAGGED_DAYS_MIN = 2;

    /** Twice the easy-day line: a day this far past it speaks for the week on its own. */
    public const float EGREGIOUS_EASY_DAY_HARD_SHARE = 0.40;

    /** Number of settled weeks that can contribute to a repeated-stimulus reduction. */
    public const int STIMULUS_HISTORY_WEEKS = 3;

    /** Projection within this fraction of the goal time is on track; neither direction fires. */
    public const float RACE_GAP_MARGIN = 0.02;

    public function __construct(
        private TrainingLoad $trainingLoad,
        private RiegelProjector $riegelProjector,
        private HydrationBacklog $hydrationBacklog,
    ) {
    }

    /**
     * Gathers this athlete's live signals and runs them through
     * {@see self::decide()}.
     *
     * @return array{reason: AdaptationReason, deload: bool, quality_delta: int, adherence_pct: int, stimulus_adherence_pct: int}
     */
    public function forWeek(User $user, Carbon $weekStart, Carbon $today, ?RaceGoal $race): array
    {
        $loadPending = $this->hydrationBacklog->recentLoadAwaitsScoring($user->id, $today);
        $load = $loadPending ? null : $this->trainingLoad->summary($user, $today);
        $ceiling = ReadinessCeiling::from(BriefingContext::forUser($user, $today, $load, historyLoading: $loadPending)->readinessCeiling);
        $execution = $this->previousWeekExecution($user, $weekStart);
        $currentStimulus = $this->currentWeekStimulus($user, $weekStart, $today);
        $stimulus = $currentStimulus['sessions'] > 0
            ? $currentStimulus
            : $this->previousWeekStimulus($user, $weekStart);

        return self::decide(
            $ceiling,
            $this->previousWeekAdherencePct($user, $weekStart),
            $stimulus['adherence_pct'],
            $stimulus['misses'],
            $stimulus['reduction_misses'],
            $execution['ragged'],
            $execution['egregious_easy'],
            $this->raceGapRatio($user, $race),
        );
    }

    /**
     * @param  int  $adherencePct  average of last week's persisted per-day distance_score (Rest/Planned/Skip days excluded, each day capped at 100 before averaging — an overreached day can't paper over a missed one)
     * @param  int  $stimulusAdherencePct  percentage of judgeable key sessions whose stimulus landed
     * @param  int  $stimulusMisses  judgeable key sessions whose stimulus was missed
     * @param  int  $stimulusMissesInWindow  missed key sessions across the settled three-week window
     * @param  int  $raggedDays  days last week whose runs came in harder than the day was written for
     * @param  int  $egregiousEasyDays  of those, easy days so far above Z2 that one is the whole verdict
     * @param  float|null  $raceGapRatio  projected finish / goal time; above 1.0 the athlete is behind their goal
     * @return array{reason: AdaptationReason, deload: bool, quality_delta: int, adherence_pct: int, stimulus_adherence_pct: int}
     */
    public static function decide(
        ReadinessCeiling $ceiling,
        int $adherencePct,
        int $stimulusAdherencePct,
        int $stimulusMisses,
        int $stimulusMissesInWindow,
        int $raggedDays,
        int $egregiousEasyDays,
        ?float $raceGapRatio,
    ): array {
        $reason = self::reasonFor($ceiling, $adherencePct, $stimulusMisses, $raggedDays, $egregiousEasyDays, $raceGapRatio);

        return [
            'reason' => $reason,
            'deload' => $reason->isDeload(),
            'quality_delta' => match ($reason) {
                AdaptationReason::BehindRacePace => 1,
                AdaptationReason::RanTooHard => -1,
                AdaptationReason::MissedStimulus => self::stimulusNeedsReduction($stimulusMissesInWindow) ? -1 : 0,
                default => 0,
            },
            'adherence_pct' => min(100, max(0, $adherencePct)),
            'stimulus_adherence_pct' => min(100, max(0, $stimulusAdherencePct)),
        ];
    }

    private static function reasonFor(
        ReadinessCeiling $ceiling,
        int $adherencePct,
        int $stimulusMisses,
        int $raggedDays,
        int $egregiousEasyDays,
        ?float $raceGapRatio,
    ): AdaptationReason {
        if ($ceiling === ReadinessCeiling::Rest) {
            return AdaptationReason::LowReadiness;
        }
        if ($adherencePct / 100 < self::MISSED_WEEK_ADHERENCE) {
            return AdaptationReason::MissedWeek;
        }
        if ($egregiousEasyDays >= 1 || $raggedDays >= self::RAGGED_DAYS_MIN) {
            return AdaptationReason::RanTooHard;
        }
        if ($stimulusMisses > 0) {
            return AdaptationReason::MissedStimulus;
        }
        if ($raceGapRatio === null) {
            return AdaptationReason::Steady;
        }

        return match (true) {
            $raceGapRatio > 1.0 + self::RACE_GAP_MARGIN => AdaptationReason::BehindRacePace,
            $raceGapRatio < 1.0 - self::RACE_GAP_MARGIN => AdaptationReason::AheadOfRacePace,
            default => AdaptationReason::Steady,
        };
    }

    private static function stimulusNeedsReduction(int $misses): bool
    {
        return $misses >= 2;
    }

    /**
     * Average of last week's persisted per-day `distance_score`, each day
     * capped at 100 before averaging (an overreached day shouldn't mask a
     * missed one — this is a "did you do enough" check, not a volume total).
     * Distance alone, never the intent-widened `compliance_score`, so a week
     * run too easily cannot read as a missed week and back the plan off.
     * Rest/still-`Planned`/`Skip` days are excluded entirely: rest asks for
     * nothing, an unscored row has no verdict yet, and a skipped day is
     * excused by definition. No scoreable days at all (first week ever, or
     * an all-rest week) reads as perfect adherence — never punish for
     * nothing to judge.
     */
    private function previousWeekAdherencePct(User $user, Carbon $weekStart): int
    {
        [$previousStart, $previousEnd] = self::previousWeekBounds($weekStart);
        $scores = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()])
            ->whereNotIn('status', [PlannedSessionStatus::Planned, PlannedSessionStatus::Skip])
            ->whereNotNull('distance_score')
            ->pluck('distance_score');

        if ($scores->isEmpty()) {
            return 100;
        }

        return (int) round($scores->map(static fn (int $score): int => min(100, $score))->avg() ?? 100.0);
    }

    /**
     * Key sessions are the sessions whose intent verdict says whether the
     * prescribed work landed. Unknown and unscored rows are missing evidence,
     * not missed work; TooHard still means the stimulus happened and remains
     * visible to the existing ran-too-hard arm.
     *
     * @return array{adherence_pct: int, misses: int, sessions: int, reduction_misses: int}
     */
    private function previousWeekStimulus(User $user, Carbon $weekStart): array
    {
        [$previousStart, $previousEnd] = self::previousWeekBounds($weekStart);
        $rows = $this->settledStimulusRows($user, $previousStart, $previousEnd);
        $windowRows = $this->settledStimulusRows(
            $user,
            $previousStart->copy()->subWeeks(self::STIMULUS_HISTORY_WEEKS - 1),
            $previousEnd,
        );

        $sessions = $rows->count();
        $misses = $rows->where('intent_verdict', IntentVerdict::Missed->value)->count();
        $successful = $sessions - $misses;

        return [
            'adherence_pct' => $sessions === 0 ? 100 : (int) round($successful / $sessions * 100),
            'misses' => $misses,
            'sessions' => $sessions,
            'reduction_misses' => $windowRows->where('intent_verdict', IntentVerdict::Missed->value)->count(),
        ];
    }

    /**
     * Settled key sessions from the current week, excluding today because the
     * day is still open and a miss cannot be decided until it closes.
     *
     * @return array{adherence_pct: int, misses: int, sessions: int, reduction_misses: int}
     */
    private function currentWeekStimulus(User $user, Carbon $weekStart, Carbon $today): array
    {
        $end = $today->copy()->subDay();
        $weekEnd = $weekStart->copy()->addDays(6);
        if ($end->gt($weekEnd)) {
            $end = $weekEnd;
        }
        if ($end->lt($weekStart)) {
            return ['adherence_pct' => 100, 'misses' => 0, 'sessions' => 0, 'reduction_misses' => 0];
        }

        $rows = $this->settledStimulusRows($user, $weekStart, $end);
        $windowRows = $this->settledStimulusRows(
            $user,
            $weekStart->copy()->subWeeks(self::STIMULUS_HISTORY_WEEKS - 1),
            $end,
        );
        $sessions = $rows->count();
        $misses = $rows->where('intent_verdict', IntentVerdict::Missed->value)->count();

        return [
            'adherence_pct' => $sessions === 0 ? 100 : (int) round(($sessions - $misses) / $sessions * 100),
            'misses' => $misses,
            'sessions' => $sessions,
            'reduction_misses' => $windowRows->where('intent_verdict', IntentVerdict::Missed->value)->count(),
        ];
    }

    /**
     * @return Collection<int, PlannedSession>
     */
    private function settledStimulusRows(User $user, Carbon $from, Carbon $to): Collection
    {
        return PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('session_type', [SessionType::Long, SessionType::Tempo, SessionType::Interval])
            ->whereIn('status', [PlannedSessionStatus::Done, PlannedSessionStatus::Partial, PlannedSessionStatus::Overreached])
            ->where('intent_evidence->quality_progression', 'eligible')
            ->whereIn('intent_verdict', [IntentVerdict::Hit->value, IntentVerdict::Missed->value, IntentVerdict::TooHard->value])
            ->get(['intent_verdict']);
    }

    /**
     * How last week was run, as counts of `Easy` days whose runs spent more
     * than {@see self::EASY_DAY_HARD_SHARE} of their moving time above Z2. The
     * `egregious_easy` count is the subset far enough past that line for one
     * day to speak for the week. Steady-segment decoupling is descriptive and
     * never read here.
     *
     * A day counts once however many runs it holds, because sessions are
     * matched to days and not to individual runs. A run carrying no
     * heart-rate stream reads as no signal rather than as a clean day: the
     * zone breakdown is absent, so the test cannot fire. Only easy days are
     * judged.
     *
     * @return array{ragged: int, egregious_easy: int}
     */
    private function previousWeekExecution(User $user, Carbon $weekStart): array
    {
        [$previousStart, $previousEnd] = self::previousWeekBounds($weekStart);

        $prescribed = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()])
            ->get(['date', 'session_type', 'rest_clamped_at', 'clamped_km', 'eased_pace_sec_per_km', 'readiness_assessment', 'prescribed_hard_minutes', 'prescribed_pace_band', 'prescribed_pace_sec_per_km', 'prescription_race_context', 'intent_evidence'])
            ->mapWithKeys(static fn (PlannedSession $session): array => [$session->date->toDateString() => EffectiveSession::settledTypeOf($session)]);

        if ($prescribed->isEmpty()) {
            return ['ragged' => 0, 'egregious_easy' => 0];
        }

        $details = ActivityDetail::query()
            ->forUser($user->id)
            ->whereNotNull('start_date_local')
            ->whereBetween('start_date_local', [$previousStart->copy()->startOfDay(), $previousEnd->copy()->endOfDay()])
            ->get(['activity_details.id', 'start_date_local', 'stream_summary']);

        $ragged = [];
        $egregiousEasy = [];
        foreach ($details as $detail) {
            $date = $detail->start_date_local?->toDateString();
            if ($date === null || $prescribed->get($date) !== SessionType::Easy) {
                continue;
            }
            $hardShare = StreamSummary::fromArray($detail->streamSummary())->hardZoneShare() / 100;
            if ($hardShare > self::EASY_DAY_HARD_SHARE) {
                $ragged[$date] = true;
            }
            if ($hardShare > self::EGREGIOUS_EASY_DAY_HARD_SHARE) {
                $egregiousEasy[$date] = true;
            }
        }

        return [
            'ragged' => count($ragged),
            'egregious_easy' => count($egregiousEasy),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon} last week's [start, end] dates
     */
    private static function previousWeekBounds(Carbon $weekStart): array
    {
        $previousStart = $weekStart->copy()->subWeek();

        return [$previousStart, $previousStart->copy()->addDays(6)];
    }

    private function raceGapRatio(User $user, ?RaceGoal $race): ?float
    {
        if ($race === null || $race->goal_time_sec <= 0) {
            return null;
        }

        $projection = $this->riegelProjector->project($user, (float) $race->distance_m);

        return $projection === null ? null : $projection['predicted_sec'] / $race->goal_time_sec;
    }
}
