<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use Illuminate\Database\Eloquent\Collection;
use App\Enums\AdaptationReason;
use App\Enums\IntentVerdict;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\DecouplingBands;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\RiegelProjector;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\BriefingContext;
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
    /** Foster's injury-risk uniformity threshold, the same one {@see \App\Services\Run\Metrics\Readiness} caps against. */
    public const float MONOTONY_DELOAD = 2.0;

    /** Weekly strain past this multiple of CTL is more than the athlete's fitness supports. */
    public const float STRAIN_TO_CTL_DELOAD = 12.0;

    /** Below this much CTL the strain ratio is noise, not signal. */
    public const float MIN_CTL_FOR_STRAIN = 10.0;

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

    /**
     * Egregiously decoupled days before the week counts as run too hard.
     *
     * Two, where an egregious share above Z2 still speaks for the week alone.
     * The two are not the same kind of evidence: a day's time above Z2 is a
     * bounded share of measured minutes, while decoupling is a ratio between
     * two derived halves, the noisier signal here. It gets the same two-day
     * bar as {@see self::RAGGED_DAYS_MIN} rather than counting alone.
     */
    public const int EGREGIOUS_DECOUPLING_DAYS_MIN = 2;

    /** Projection within this fraction of the goal time is on track; neither direction fires. */
    public const float RACE_GAP_MARGIN = 0.02;

    public function __construct(
        private TrainingLoad $trainingLoad,
        private RiegelProjector $riegelProjector,
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
        $load = $this->trainingLoad->summary($user, $today);
        $ceiling = ReadinessCeiling::from(BriefingContext::forUser($user, $today, $load)->readinessCeiling);
        $execution = $this->previousWeekExecution($user, $weekStart);
        $currentStimulus = $this->currentWeekStimulus($user, $weekStart, $today);
        $stimulus = $currentStimulus['sessions'] > 0
            ? $currentStimulus
            : $this->previousWeekStimulus($user, $weekStart);

        return self::decide(
            $ceiling,
            self::floatOrNull($load['monotony'] ?? null),
            self::floatOrNull($load['strain'] ?? null),
            self::floatOrNull($load['ctl_42d'] ?? null),
            $this->previousWeekAdherencePct($user, $weekStart),
            $stimulus['adherence_pct'],
            $stimulus['misses'],
            $stimulus['reduction_misses'],
            $execution['ragged'],
            $execution['egregious_easy'],
            $execution['egregious_decoupling'],
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
     * @param  int  $egregiousDecouplingDays  of those, quality days so far past the decoupling line that {@see self::EGREGIOUS_DECOUPLING_DAYS_MIN} of them are the verdict
     * @param  float|null  $raceGapRatio  projected finish / goal time; above 1.0 the athlete is behind their goal
     * @return array{reason: AdaptationReason, deload: bool, quality_delta: int, adherence_pct: int, stimulus_adherence_pct: int}
     */
    public static function decide(
        ReadinessCeiling $ceiling,
        ?float $monotony,
        ?float $strain,
        ?float $ctl,
        int $adherencePct,
        int $stimulusAdherencePct,
        int $stimulusMisses,
        int $stimulusMissesInWindow,
        int $raggedDays,
        int $egregiousEasyDays,
        int $egregiousDecouplingDays,
        ?float $raceGapRatio,
    ): array {
        $reason = self::reasonFor($ceiling, $monotony, $strain, $ctl, $adherencePct, $stimulusMisses, $raggedDays, $egregiousEasyDays, $egregiousDecouplingDays, $raceGapRatio);

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
        ?float $monotony,
        ?float $strain,
        ?float $ctl,
        int $adherencePct,
        int $stimulusMisses,
        int $raggedDays,
        int $egregiousEasyDays,
        int $egregiousDecouplingDays,
        ?float $raceGapRatio,
    ): AdaptationReason {
        if ($ceiling === ReadinessCeiling::Rest) {
            return AdaptationReason::LowReadiness;
        }
        if ($monotony !== null && $monotony >= self::MONOTONY_DELOAD) {
            return AdaptationReason::HighMonotony;
        }
        if (self::strainIsExcessive($strain, $ctl)) {
            return AdaptationReason::HighStrain;
        }
        if ($adherencePct / 100 < self::MISSED_WEEK_ADHERENCE) {
            return AdaptationReason::MissedWeek;
        }
        if ($egregiousEasyDays >= 1
            || $egregiousDecouplingDays >= self::EGREGIOUS_DECOUPLING_DAYS_MIN
            || $raggedDays >= self::RAGGED_DAYS_MIN) {
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

    private static function strainIsExcessive(?float $strain, ?float $ctl): bool
    {
        if ($strain === null || $ctl === null || $ctl < self::MIN_CTL_FOR_STRAIN) {
            return false;
        }

        return $strain > $ctl * self::STRAIN_TO_CTL_DELOAD;
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
            ->whereIn('intent_verdict', [IntentVerdict::Hit->value, IntentVerdict::Missed->value, IntentVerdict::TooHard->value])
            ->get(['intent_verdict']);
    }

    /**
     * How last week was run, as counts of days whose runs came in harder than
     * the day was written for: an `Easy` day that spent more than
     * {@see self::EASY_DAY_HARD_SHARE} of its moving time above Z2, or a
     * `Long`/`Tempo`/`Interval` day whose decoupling ran past
     * {@see DecouplingBands::HIGH}. The two `egregious` counts are the subsets
     * far enough past those lines to weigh more than one merely ragged day, and
     * they are kept apart because {@see self::reasonFor} asks a different
     * number of each.
     *
     * A day counts once however many runs it holds, because sessions are
     * matched to days and not to individual runs. A run carrying no
     * heart-rate stream reads as no signal rather than as a clean day: the
     * zone breakdown is absent and decoupling is withheld, so neither test
     * can fire. Rest and race days are never judged.
     *
     * @return array{ragged: int, egregious_easy: int, egregious_decoupling: int}
     */
    private function previousWeekExecution(User $user, Carbon $weekStart): array
    {
        [$previousStart, $previousEnd] = self::previousWeekBounds($weekStart);

        $prescribed = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()])
            ->get(['date', 'session_type'])
            ->mapWithKeys(static fn (PlannedSession $session): array => [$session->date->toDateString() => $session->session_type]);

        if ($prescribed->isEmpty()) {
            return ['ragged' => 0, 'egregious_easy' => 0, 'egregious_decoupling' => 0];
        }

        $details = ActivityDetail::query()
            ->forUser($user->id)
            ->whereNotNull('start_date_local')
            ->whereBetween('start_date_local', [$previousStart->copy()->startOfDay(), $previousEnd->copy()->endOfDay()])
            ->get(['id', 'start_date_local', 'stream_summary']);

        $ragged = [];
        $egregiousEasy = [];
        $egregiousDecoupling = [];
        foreach ($details as $detail) {
            $date = $detail->start_date_local?->toDateString();
            $type = $date === null ? null : $prescribed->get($date);
            if (! $type instanceof SessionType) {
                continue;
            }
            $summary = StreamSummary::fromArray($detail->streamSummary());
            if (self::ranHarderThanWritten($type, $summary, self::EASY_DAY_HARD_SHARE, DecouplingBands::HIGH)) {
                $ragged[$date] = true;
            }
            if (self::ranHarderThanWritten($type, $summary, self::EGREGIOUS_EASY_DAY_HARD_SHARE, null)) {
                $egregiousEasy[$date] = true;
            }
            if (self::ranHarderThanWritten($type, $summary, null, DecouplingBands::EGREGIOUS)) {
                $egregiousDecoupling[$date] = true;
            }
        }

        return [
            'ragged' => count($ragged),
            'egregious_easy' => count($egregiousEasy),
            'egregious_decoupling' => count($egregiousDecoupling),
        ];
    }

    /**
     * A null line means that arm is not being counted on this pass, so the
     * caller can ask about one kind of day without the other answering too.
     *
     * The two lines carry different units: this class's thresholds are
     * fractions, while {@see DecouplingBands}'s decoupling line stays percent.
     */
    private static function ranHarderThanWritten(SessionType $type, StreamSummary $summary, ?float $easyShareLine, ?float $decouplingPctLine): bool
    {
        return match ($type) {
            SessionType::Easy => $easyShareLine !== null && $summary->hardZoneShare() / 100 > $easyShareLine,
            SessionType::Long, SessionType::Tempo, SessionType::Interval => $decouplingPctLine !== null && ($summary->decouplingPct() ?? 0.0) > $decouplingPctLine,
            SessionType::Rest, SessionType::Race => false,
        };
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

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
