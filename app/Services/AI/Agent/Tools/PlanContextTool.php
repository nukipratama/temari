<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TrainingBaseline;
use App\Services\Run\Plan\WeekPlanBuilder;
use Illuminate\Support\Carbon;

/**
 * What the periodizer prescribed over a span, and how the athlete did against
 * it. Bound to the span the calling block is about, so a day narrator, a weekly
 * recap and a per-run narrator all ask the same question of a different window.
 *
 * `prescribed_km` is written by {@see \App\Services\Run\Plan\ComplianceScorer}
 * the morning after a day passes, so it is null for today and every future day;
 * those fall back to the same unredistributed core distance {@see PlanDayTool}
 * reports. The target pace comes from the athlete's current VDOT rather than
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
            .'planned), a compliance score out of 100, and ran_anyway true when they ran a day they '
            .'had excused themselves from. skipped true means they excused the day. Call this to say '
            .'what was asked of them, not just what they did. An empty list means no plan covers '
            .'these days.';
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

        $longRunBaselineKm = $this->baseline->forUser($this->user, $this->asOf)['long_run_km'];
        $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($this->user, $this->asOf)) ?? [];

        return [
            'days' => $sessions->map(function (PlannedSession $session) use ($paces, $longRunBaselineKm): array {
                $targetPaceSec = self::targetPaceSec($session, $paces);

                return [
                    'date' => $session->date->toDateString(),
                    'session_type' => $session->session_type->value,
                    'phase' => $session->phase->value,
                    'distance_km' => $session->prescribed_km !== null
                        ? round($session->prescribed_km, 1)
                        : SegmentGenerator::coreKmForPlannedSession($session, $longRunBaselineKm),
                    'target_pace_sec' => $targetPaceSec,
                    'target_pace_formatted' => $targetPaceSec === null
                        ? null
                        : PaceFormatter::format((float) $targetPaceSec),
                    'skipped' => $session->skipped,
                    'status' => $session->status->value,
                    'compliance_score' => $session->compliance_score,
                    'ran_anyway' => $session->ran_anyway,
                ];
            })->all(),
        ];
    }

    /**
     * Mirrors {@see SegmentGenerator::raceSegments()}: a race short of marathon
     * distance is raced at threshold effort, not marathon effort.
     *
     * @param  array<string, int|null>  $paces  Empty until the athlete's PR history can estimate a VDOT.
     */
    private static function targetPaceSec(PlannedSession $session, array $paces): ?int
    {
        return match ($session->session_type) {
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
