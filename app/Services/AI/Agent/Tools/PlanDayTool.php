<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Enums\IntentVerdict;
use App\Models\PlannedSession;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use App\Services\Run\Plan\EffectiveSession;
use App\Services\Run\Plan\IntentOutcome;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Support\Carbon;

/**
 * The prescribed session for one day: type, phase, and a rough core
 * distance, plus how the day actually went once it has been graded.
 * Deliberately the unredistributed figure rather than
 * {@see \App\Services\Run\Plan\PlanPageAssembler}'s exact render-time number:
 * the day's plan can still shift before it's actually run, so the
 * narration only needs to be qualitatively right, not pixel-matched to a
 * number the UI itself may later redistribute. It still carries the same
 * week multiplier and primary-easy sizing the UI's own "asked" figure does
 * ({@see PlanRenderer::coreKmForSession()}) — only the live per-day
 * redistribution is left out.
 *
 * A readiness-eased day is described as the session it became, read through
 * {@see EffectiveSession} like every other surface, with the original named as
 * context. The clamp still never reaches
 * {@see \App\Services\AI\MaterialFingerprint} — see
 * `docs/decisions/the-eased-session-leads.md`.
 *
 * Once the day is credited it also carries the intent verdict
 * {@see \App\Services\Run\Plan\ComplianceScorer} persisted alongside the grade,
 * read back rather than recomputed and worded by {@see IntentOutcome} — see
 * `docs/decisions/a-day-is-graded-on-distance-and-intent.md`. The read can
 * therefore never disagree with the grade: both come from the same row.
 */
final class PlanDayTool extends NoArgumentTool
{
    public function __construct(
        private readonly PlannedSession $session,
        private readonly TrainingBaseline $baseline,
        private readonly VdotEstimator $vdotEstimator,
        private readonly TrainingPaceCalculator $paceCalculator,
        /** Km actually run on this date, or null while nothing has been logged. */
        private readonly ?float $completedKm = null,
        /** The elapsed pace the day's card shows, or null while nothing has been logged. */
        private readonly ?int $ranPaceSecPerKm = null,
    ) {
    }

    public function name(): string
    {
        return 'get_day_plan';
    }

    public function description(): string
    {
        return 'The prescribed session for this day: type (easy/long/tempo/interval/rest/race), '
            .'training phase, and an approximate distance in km. skipped true means the athlete '
            .'has already excused themselves from this day. Once the day has been run it also '
            .'carries how it went: status (done/partial/missed/overreached), completed_km, and '
            .'ran_anyway true when they ran a day they had excused. Those four are absent on a '
            .'day that has not been graded yet, which means it is still ahead of the athlete. '
            .'On a credited day, intent says in plain words whether the session did the job it was '
            .'written for, already decided — repeat its meaning, never re-judge it or make it '
            .'stronger. intent_detail, when present, is the evidence as a sentence with every '
            .'comparison already worded; keep its direction exactly as written. intent is absent on '
            .'a rest or race day, or one with no intent to judge. eased_from means readiness eased the day: session_type and distance_km are the '
            .'eased session the athlete is actually doing, and eased_from names the session it '
            .'replaced (with its distance only when that moved). Describe the eased session as the '
            .'day, and the replaced one only as what it was eased from. pace_sec/pace_formatted are '
            .'present only when readiness eased the day\'s pace only: they are the eased (slower) '
            .'pace the athlete is actually running, and pace_eased_from names the pace it replaced.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $baselineData = $this->baseline->forUser($this->session->user, Carbon::today());
        $storedCoreKm = $this->session->prescribed_km === null || EffectiveSession::isRecordedOn($this->session)
            ? PlanRenderer::coreKmForSession(
                $this->session,
                $baselineData['long_run_km'],
                $baselineData['long_run_cap_km'],
                $baselineData['self_scaled'],
                $baselineData['long_run_progression_cap_km'],
            )
            : round((float) $this->session->prescribed_km, 1);
        $effective = EffectiveSession::of(
            $this->session,
            $storedCoreKm,
        );
        $easedFrom = $effective->easedFromForNarration();
        $paceFields = [];
        if ($effective->isPaceEased()) {
            $paceFields['pace_sec'] = $effective->easedPaceSecPerKm;
            $paceFields['pace_formatted'] = PaceFormatter::format((float) $effective->easedPaceSecPerKm);
            $originalPaceSec = $this->paceCalculator->fromVdotResult(
                $this->vdotEstimator->estimate($this->session->user, Carbon::today())
            )['easy'] ?? null;
            if ($originalPaceSec !== null) {
                $paceFields['pace_eased_from'] = [
                    'pace_sec' => $originalPaceSec,
                    'pace_formatted' => PaceFormatter::format((float) $originalPaceSec),
                ];
            }
        }

        return [
            'date' => $this->session->date->toDateString(),
            'session_type' => $effective->sessionType->value,
            'phase' => $this->session->phase->value,
            'distance_km' => $effective->coreKm,
            ...($easedFrom === null ? [] : ['eased_from' => $easedFrom]),
            ...$paceFields,
            'skipped' => $this->session->skipped,
            // Absent rather than null on an ungraded day: a key that is always
            // there teaches the model the day is over even when it is not.
            ...($this->session->status->isCredited() ? [
                'status' => $this->session->status->value,
                'completed_km' => $this->completedKm,
                'ran_anyway' => $this->session->ran_anyway,
                ...($this->session->intent_verdict === null ? [] : $this->intent($this->session->intent_verdict)),
            ] : []),
        ];
    }

    /** @return array{intent: string, intent_detail?: string} */
    private function intent(IntentVerdict $verdict): array
    {
        $evidence = $this->session->intent_evidence ?? [];
        $intent = ['intent' => IntentOutcome::outcome($verdict, $evidence)];
        $detail = IntentOutcome::detail($verdict, $evidence, $this->ranPaceSecPerKm);
        if ($detail !== null) {
            $intent['intent_detail'] = $detail;
        }

        return $intent;
    }
}
