<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\AdaptationReason;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
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

    /** Below this average per-day compliance score, last week counts as a re-entry, not a catch-up. */
    public const int MISSED_WEEK_ADHERENCE = 50;

    /** Share of an easy day's moving time above Z2 that stops it being an easy day. */
    public const float EASY_DAY_HARD_SHARE = 20.0;

    /** Decoupling past this on a long or quality day, the same line {@see \App\Services\AI\RuleBased\RuleBasedRunInsights} calls high. */
    public const float HIGH_DECOUPLING = 5.0;

    /** One ragged day is a bad morning. This many is how the week was run. */
    public const int RAGGED_DAYS_MIN = 2;

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
     * @return array{reason: AdaptationReason, deload: bool, quality_delta: int, adherence_pct: int}
     */
    public function forWeek(User $user, Carbon $weekStart, Carbon $today, ?RaceGoal $race): array
    {
        $load = $this->trainingLoad->summary($user, $today);
        $ceiling = ReadinessCeiling::from(BriefingContext::forUser($user, $today, $load)->readinessCeiling);

        return self::decide(
            $ceiling,
            self::floatOrNull($load['monotony'] ?? null),
            self::floatOrNull($load['strain'] ?? null),
            self::floatOrNull($load['ctl_42d'] ?? null),
            $this->previousWeekAdherencePct($user, $weekStart),
            $this->previousWeekRaggedDays($user, $weekStart),
            $this->raceGapRatio($user, $race),
        );
    }

    /**
     * @param  int  $adherencePct  average of last week's persisted per-day compliance_score (Rest/Planned/Skip days excluded, each day capped at 100 before averaging — an overreached day can't paper over a missed one)
     * @param  int  $raggedDays  days last week whose runs came in harder than the day was written for
     * @param  float|null  $raceGapRatio  projected finish / goal time; above 1.0 the athlete is behind their goal
     * @return array{reason: AdaptationReason, deload: bool, quality_delta: int, adherence_pct: int}
     */
    public static function decide(
        ReadinessCeiling $ceiling,
        ?float $monotony,
        ?float $strain,
        ?float $ctl,
        int $adherencePct,
        int $raggedDays,
        ?float $raceGapRatio,
    ): array {
        $reason = self::reasonFor($ceiling, $monotony, $strain, $ctl, $adherencePct, $raggedDays, $raceGapRatio);

        return [
            'reason' => $reason,
            'deload' => $reason->isDeload(),
            'quality_delta' => match ($reason) {
                AdaptationReason::BehindRacePace => 1,
                AdaptationReason::RanTooHard, AdaptationReason::AheadOfRacePace => -1,
                default => 0,
            },
            'adherence_pct' => min(100, max(0, $adherencePct)),
        ];
    }

    private static function reasonFor(
        ReadinessCeiling $ceiling,
        ?float $monotony,
        ?float $strain,
        ?float $ctl,
        int $adherencePct,
        int $raggedDays,
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
        if ($adherencePct < self::MISSED_WEEK_ADHERENCE) {
            return AdaptationReason::MissedWeek;
        }
        if ($raggedDays >= self::RAGGED_DAYS_MIN) {
            return AdaptationReason::RanTooHard;
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

    private static function strainIsExcessive(?float $strain, ?float $ctl): bool
    {
        if ($strain === null || $ctl === null || $ctl < self::MIN_CTL_FOR_STRAIN) {
            return false;
        }

        return $strain > $ctl * self::STRAIN_TO_CTL_DELOAD;
    }

    /**
     * Average of last week's persisted per-day `compliance_score`, each day
     * capped at 100 before averaging (an overreached day shouldn't mask a
     * missed one — this is a "did you do enough" check, not a volume total).
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
            ->whereNotNull('compliance_score')
            ->pluck('compliance_score');

        if ($scores->isEmpty()) {
            return 100;
        }

        return (int) round($scores->map(static fn (int $score): int => min(100, $score))->avg() ?? 100.0);
    }

    /**
     * Days last week whose runs came in harder than the day was written for:
     * an `Easy` day that spent more than {@see self::EASY_DAY_HARD_SHARE} of
     * its moving time above Z2, or a `Long`/`Tempo`/`Interval` day whose
     * decoupling ran past {@see self::HIGH_DECOUPLING}. A day counts once
     * however many runs it holds, because sessions are matched to days and
     * not to individual runs.
     *
     * A run carrying no heart-rate stream reads as no signal rather than as a
     * clean day: the zone breakdown is absent and decoupling is withheld, so
     * neither test can fire. Rest and race days are never judged.
     */
    private function previousWeekRaggedDays(User $user, Carbon $weekStart): int
    {
        [$previousStart, $previousEnd] = self::previousWeekBounds($weekStart);

        $prescribed = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$previousStart->toDateString(), $previousEnd->toDateString()])
            ->get(['date', 'session_type'])
            ->mapWithKeys(static fn (PlannedSession $session): array => [$session->date->toDateString() => $session->session_type]);

        if ($prescribed->isEmpty()) {
            return 0;
        }

        $details = ActivityDetail::query()
            ->forUser($user->id)
            ->whereNotNull('start_date_local')
            ->whereBetween('start_date_local', [$previousStart->copy()->startOfDay(), $previousEnd->copy()->endOfDay()])
            ->get(['id', 'start_date_local', 'stream_summary']);

        $ragged = [];
        foreach ($details as $detail) {
            $date = $detail->start_date_local?->toDateString();
            $type = $date === null ? null : $prescribed->get($date);
            if (! $type instanceof SessionType || isset($ragged[$date])) {
                continue;
            }
            if (self::ranHarderThanWritten($type, StreamSummary::fromArray($detail->streamSummary()))) {
                $ragged[$date] = true;
            }
        }

        return count($ragged);
    }

    private static function ranHarderThanWritten(SessionType $type, StreamSummary $summary): bool
    {
        return match ($type) {
            SessionType::Easy => $summary->hardZoneShare() > self::EASY_DAY_HARD_SHARE,
            SessionType::Long, SessionType::Tempo, SessionType::Interval => ($summary->decouplingPct() ?? 0.0) > self::HIGH_DECOUPLING,
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
