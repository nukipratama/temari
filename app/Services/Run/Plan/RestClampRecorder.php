<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\SessionType;
use App\Enums\PlannedSessionStatus;
use App\Models\PlannedSession;
use App\Models\User;
use App\Notifications\DayClampedNotification;
use App\Services\AI\HydrationBacklog;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Story\BriefingContext;
use Illuminate\Support\Carbon;

/**
 * Records the clamp outcomes that have to outlive the render: a today
 * downgraded to a full rest, the eased distance a lighter downgrade asked for
 * instead, and — on a day that already clears the ceiling but only just — the
 * eased pace {@see ReadinessClamp::paceEaseApplies()} asks for.
 *
 * {@see ReadinessClamp} is otherwise deliberately render-only, and that works
 * because every other consumer recomputes it. Compliance cannot — it runs the
 * next morning, and the ceiling is derived from
 * {@see TrainingLoad::summary()}, which counts the day's own runs, so the
 * ceiling that produced an 08:00 clamp no longer exists at 00:03. Without a
 * record, an athlete who took the rest the card prescribed is graded against
 * the session it replaced and scores `missed` for complying.
 *
 * Called by daily-briefing side effects and current-day feedback saves.
 */
final readonly class RestClampRecorder
{
    public function __construct(
        private TrainingLoad $trainingLoad,
        private TrainingBaseline $baseline,
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $paceCalculator,
        private HydrationBacklog $hydrationBacklog,
    ) {
    }

    /**
     * The week's volume multiplier, read the same way {@see CurrentWeekPlanBuilder}
     * and {@see PlanPageAssembler} do — the same trailing
     * window resolves to the same multiplier for the shared week. Needed so
     * the eased distance recorded here matches the one the render actually
     * showed: {@see ReadinessClamp::apply()}'s core_km scales with it, and a
     * hardcoded 1.0 would only agree with the render by coincidence, in the
     * one phase where the ramp has not moved off it yet.
     */
    private function volumeMultiplierFor(User $user, Carbon $today, bool $selfScaled): float
    {
        $currentWeekStart = $today->copy()->startOfWeek(Carbon::MONDAY);

        $sessions = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [
                $currentWeekStart->copy()->subWeeks(PlanRenderer::HISTORY_WEEKS)->toDateString(),
                $currentWeekStart->copy()->addDays(6)->toDateString(),
            ])
            ->orderBy('date')
            ->get();

        if ($sessions->isEmpty()) {
            return 1.0;
        }

        $sessionsByWeek = $sessions->groupBy(
            fn (PlannedSession $s): string => $s->date->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
        );
        [, $multiplierByWeek] = PlanRenderer::weekPhasesAndMultipliers($sessionsByWeek, $selfScaled);

        return $multiplierByWeek[$currentWeekStart->toDateString()] ?? 1.0;
    }

    public function record(User $user, Carbon $today): bool
    {
        $session = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', $today->toDateString())
            ->first();

        if ($session === null || $session->pinned || $session->session_type === SessionType::Race
            || $session->status !== PlannedSessionStatus::Planned || $session->skipped) {
            return false;
        }

        $loadPending = $this->hydrationBacklog->recentLoadAwaitsScoring($user->id, $today);
        TrainingLoad::clearSummaryCache($user);

        $context = BriefingContext::forUser(
            $user,
            $today,
            $loadPending ? null : $this->trainingLoad->summary($user, $today),
            historyLoading: $loadPending,
        );
        $strongHealthConcern = array_intersect(
            $context->readinessAssessment['reasons'],
            ['concerning_pain_reported', 'illness_reported'],
        ) !== [];
        if (($loadPending && ! $strongHealthConcern) || $context->ranToday) {
            return false;
        }

        $ceiling = ReadinessCeiling::from($context->readinessCeiling);
        $baselineData = $this->baseline->forUser($user, $today);
        $prescription = IntensityPrescription::fromSession($session);
        $paces = $prescription === null ? null : $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user, $today));
        $clamp = ReadinessClamp::apply(
            $session->session_type,
            $session->phase,
            $session->race_distance_m === null ? null : (float) $session->race_distance_m,
            (float) $baselineData['long_run_km'],
            $this->volumeMultiplierFor($user, $today, $baselineData['self_scaled']),
            (float) $baselineData['long_run_cap_km'],
            $paces,
            $ceiling,
            (float) $baselineData['long_run_progression_cap_km'],
            $context->readinessAssessment['reasons'],
            $prescription,
        );

        $adjustment = [
            'rest_clamped_at' => null,
            'clamped_km' => null,
            'eased_pace_sec_per_km' => null,
        ];
        $notification = null;
        if ($clamp !== null) {
            if ($clamp['session_type'] === SessionType::Rest) {
                $adjustment['rest_clamped_at'] = $session->rest_clamped_at ?? Carbon::now();
            } else {
                $adjustment['clamped_km'] = $clamp['core_km'];
            }
            $notification = ['session_type' => $clamp['session_type'], 'note' => $clamp['note']];
        } elseif (ReadinessClamp::paceEaseApplies($session->session_type, $ceiling)) {
            $easedPace = $this->paceCalculator->easySlowEndFromVdotResult($this->vdotEstimator->estimate($user, $today));
            if ($easedPace !== null) {
                $adjustment['eased_pace_sec_per_km'] = $easedPace;
            } elseif ($session->eased_pace_sec_per_km !== null) {
                $adjustment['eased_pace_sec_per_km'] = $session->eased_pace_sec_per_km;
            }
        }

        $assessment = $context->readinessAssessment;
        if (isset($clamp['quality_dose'])) {
            $assessment['adjustment'] = ['quality_dose' => $clamp['quality_dose']];
        }
        if ($session->readiness_assessment !== null
            && $session->readiness_assessment['ceiling'] === $assessment['ceiling']
            && ($session->readiness_assessment['adjustment'] ?? null) === ($assessment['adjustment'] ?? null)
            && $session->readiness_assessment['reasons'] === $assessment['reasons']) {
            $assessment = $session->readiness_assessment;
        }
        $hasAdjustment = $adjustment['rest_clamped_at'] !== null
            || $adjustment['clamped_km'] !== null
            || $adjustment['eased_pace_sec_per_km'] !== null;
        $attributes = [
            ...$adjustment,
            'readiness_assessment' => $hasAdjustment ? $assessment : null,
        ];
        $changed = $session->rest_clamped_at != $attributes['rest_clamped_at']
            || $session->clamped_km != $attributes['clamped_km']
            || $session->eased_pace_sec_per_km !== $attributes['eased_pace_sec_per_km']
            || $session->readiness_assessment !== $attributes['readiness_assessment'];
        if (! $changed) {
            return false;
        }

        $oldClampedKm = $session->clamped_km;
        $oldDose = $session->readiness_assessment['adjustment']['quality_dose'] ?? null;
        $oldSessionType = $session->rest_clamped_at !== null
            ? SessionType::Rest
            : ($session->clamped_km !== null ? SessionType::Easy : $session->session_type);
        $newSessionType = $adjustment['rest_clamped_at'] !== null
            ? SessionType::Rest
            : ($adjustment['clamped_km'] !== null ? SessionType::Easy : $session->session_type);
        $session->update($attributes);

        if ($notification !== null && ($oldSessionType !== $newSessionType || $oldClampedKm != $adjustment['clamped_km'] || $oldDose !== ($assessment['adjustment']['quality_dose'] ?? null))) {
            $this->tell($user, $today, $notification['session_type'], $notification['note']);
        }

        return true;
    }

    private function tell(User $user, Carbon $today, SessionType $clampedTo, string $note): void
    {
        $user->notify(new DayClampedNotification($today->toDateString(), $clampedTo, $note));
    }
}
