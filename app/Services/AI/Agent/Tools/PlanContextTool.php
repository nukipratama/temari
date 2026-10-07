<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Enums\IngestState;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Plan\IntentOutcome;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\SessionMatcher;
use App\Services\Run\Plan\TimeTrial;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Plan\WeekPlanBuilder;
use Illuminate\Support\Carbon;

/**
 * What the periodizer prescribed over a span, and how the athlete did against
 * it. Bound to the span the calling block is about, so a weekly recap and a
 * per-run narrator ask the same question of a different window.
 *
 * `prescribed_km` is written by {@see \App\Services\Run\Plan\ComplianceScorer}
 * the morning after a day passes, so it is null for today and every future day;
 * those fall back to the unredistributed core distance. The target pace comes from the athlete's current VDOT rather than
 * the row, which stores none.
 */
final class PlanContextTool extends UserTool
{
    public function __construct(
        User $user,
        Carbon $asOf,
        private readonly Carbon $through,
        private readonly TrainingBaseline $baseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
    ) {
        parent::__construct($user, $asOf);
    }

    public function name(): string
    {
        return 'get_planned_sessions';
    }

    public function description(): string
    {
        return 'What the training plan prescribed over the days this block covers: session type '
            .'(easy/long/tempo/interval/rest/race), training phase, distance in km, and the target '
            .'pace as target_pace_formatted (mm:ss/km, the only form to quote) with target_pace_sec '
            .'(raw seconds, for judging size, never for quoting). For days that have already been '
            .'graded it also returns how the athlete did: status (done/partial/missed/overreached/'
            .'planned), completed_km for the day total, credited_km for the distance used by '
            .'distance_score (the longest run on tempo/interval days, the day total otherwise), '
            .'and distance_score for the '
            .'distance-only percentage, and compliance_score after the intent adjustment, '
            .'intent when the day was judged: plain words for whether the session did the job it '
            .'was written for, already decided, so repeat its meaning and never re-judge or label '
            .'it; and ran_anyway true '
            .'when they ran a day they had excused themselves from. skipped true means they excused '
            .'the day. eased_from means '
            .'readiness eased the day: session_type, distance_km and the pace are the eased session '
            .'they are actually doing, and eased_from names the session it replaced (with its distance '
            .'only when that moved), which is context, never the day itself. pace_eased_from means '
            .'readiness eased the day\'s pace only: type and distance are unchanged, target_pace_sec/'
            .'target_pace_formatted are already the eased (slower) pace, and pace_eased_from names the '
            .'pace it replaced. goal_pace, when present, names the race (5k/10k/half/marathon) whose '
            .'goal pace the session rehearses, and the target pace is that goal pace: call it goal-pace '
            .'work, not tempo or intervals. stepping_stone, when present instead, names the race whose '
            .'stepping-stone pace the session rehearses, and the target pace is that stepping-stone pace: '
            .'the edge of on track, 3% faster than the time their running supports, not their goal. Call '
            .'it stepping-stone work, never goal pace, tempo or intervals. '
            .'time_trial, when present, means the day is an all-out time '
            .'trial over time_trial.distance_km after a warmup, aiming around time_trial.aim_time, the '
            .'supported time at that distance: call it a time trial, not tempo or intervals; it checks '
            .'their fitness so their paces stay honest. Call this to say what was asked of them, not just what they did. An '
            .'empty list means no plan covers these days.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $sessions = PlannedSession::query()
            ->where('user_id', $this->user->id)
            ->whereBetween('date', [$this->asOf->toDateString(), $this->through->toDateString()])
            ->orderBy('date')
            ->get();

        if ($sessions->isEmpty()) {
            return ['days' => []];
        }

        $runDistancesByDate = $this->runDistancesByDate();

        $baselineData = $this->baseline->forUser($this->user, $this->asOf);
        $longRunBaselineKm = $baselineData['long_run_km'];
        $longRunCapKm = $baselineData['long_run_cap_km'];
        $longRunProgressionCapKm = $baselineData['long_run_progression_cap_km'];
        $selfScaled = $baselineData['self_scaled'];
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($this->user, $this->asOf)) ?? [];

        return [
            'days' => $sessions->map(function (PlannedSession $session) use ($runDistancesByDate, $paces, $longRunBaselineKm, $longRunCapKm, $longRunProgressionCapKm, $selfScaled): array {
                $effective = EffectiveSession::of(
                    $session,
                    PlanRenderer::coreKmForSession($session, $longRunBaselineKm, $longRunCapKm, $selfScaled, $longRunProgressionCapKm),
                );
                $goalPace = PlanRenderer::goalPaceForNarration($session, $effective->sessionType);
                $timeTrial = PlanRenderer::timeTrialOf($session, $effective->sessionType);
                $targetPaceSec = $goalPace === [] && $timeTrial === null
                    ? self::targetPaceSec($session, $effective->sessionType, $paces)
                    : $session->prescribed_pace_sec_per_km;
                $easedFrom = $effective->easedFromForNarration();
                $paceEasedFromSec = $effective->isPaceEased() ? $targetPaceSec : null;
                if ($effective->isPaceEased()) {
                    $targetPaceSec = $effective->easedPaceSecPerKm;
                }

                $runDistances = $runDistancesByDate[$session->date->toDateString()] ?? null;

                return [
                    'date' => $session->date->toDateString(),
                    'session_type' => $effective->sessionType->value,
                    ...$goalPace,
                    ...($timeTrial === null ? [] : ['time_trial' => PlanRenderer::timeTrialForNarration($timeTrial)]),
                    'phase' => $session->phase->value,
                    'distance_km' => $session->prescribed_km !== null
                        ? round($session->prescribed_km, 1)
                        : $effective->coreKm,
                    ...($easedFrom === null ? [] : ['eased_from' => $easedFrom]),
                    'target_pace_sec' => $targetPaceSec,
                    'target_pace_formatted' => $targetPaceSec === null
                        ? null
                        : PaceFormatter::format((float) $targetPaceSec),
                    ...($paceEasedFromSec === null ? [] : ['pace_eased_from' => [
                        'pace_sec' => $paceEasedFromSec,
                        'pace_formatted' => PaceFormatter::format((float) $paceEasedFromSec),
                    ]]),
                    'skipped' => $session->skipped,
                    'status' => $session->status->value,
                    'completed_km' => $runDistances === null ? null : round($runDistances['sum'], 1),
                    'credited_km' => $runDistances === null ? null : round(SessionMatcher::creditedKm($session->session_type, $runDistances, TimeTrial::of($session) !== null), 1),
                    'distance_score' => $session->distance_score,
                    'compliance_score' => $session->compliance_score,
                    'ran_anyway' => $session->ran_anyway,
                    'intent' => $session->intent_verdict === null
                        ? null
                        : IntentOutcome::outcome($session->intent_verdict, $session->intent_evidence ?? []),
                ];
            })->all(),
        ];
    }

    /** @return array<string, array{sum: float, longest: float}> */
    private function runDistancesByDate(): array
    {
        $details = Activity::analyzedJoinConstraint(
            ActivityDetail::query()->join('activities', 'activities.id', '=', 'activity_details.activity_id'),
        )
            ->where('activities.user_id', $this->user->id)
            ->where('activities.ingest_state', IngestState::Detailed->value)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [$this->asOf->copy()->startOfDay(), $this->through->copy()->endOfDay()])
            ->get(['activity_details.start_date_local', 'activity_details.distance']);

        $metersByDate = [];
        foreach ($details as $detail) {
            $startDateLocal = $detail->getAttribute('start_date_local');
            if ($startDateLocal === null) {
                continue;
            }
            $date = Carbon::parse((string) $startDateLocal)->toDateString();

            $metersByDate[$date] = [
                'sum' => ($metersByDate[$date]['sum'] ?? 0.0) + (float) $detail->distance,
                'longest' => max($metersByDate[$date]['longest'] ?? 0.0, (float) $detail->distance),
            ];
        }

        return array_map(static fn (array $distances): array => [
            'sum' => DistanceFormatter::km($distances['sum']),
            'longest' => DistanceFormatter::km($distances['longest']),
        ], $metersByDate);
    }

    /**
     * Mirrors {@see SegmentGenerator::raceSegments()}: a race short of marathon
     * distance is raced at threshold effort, not marathon effort.
     *
     * @param  array<string, int|null>  $paces  Empty until the athlete's PR history can estimate a VDOT.
     */
    private static function targetPaceSec(PlannedSession $session, SessionType $sessionType, array $paces): ?int
    {
        return match ($sessionType) {
            SessionType::Easy, SessionType::Long => $paces['easy'] ?? null,
            SessionType::Tempo => $paces['threshold'] ?? null,
            SessionType::Interval => $paces['interval'] ?? null,
            SessionType::Race => WeekPlanBuilder::isMarathonDistance(
                $session->race_distance_m === null ? null : (float) $session->race_distance_m
            ) ? $paces['marathon'] ?? null : $paces['threshold'] ?? null,
            SessionType::Rest => null,
        };
    }
}
