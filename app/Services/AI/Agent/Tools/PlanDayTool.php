<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PlannedSession;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Plan\EffectiveSession;
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
 * {@see \App\Services\Run\Plan\ComplianceScorer} persisted alongside the grade
 * ({@see \App\Enums\IntentVerdict}) and the numbers that justify it, read back
 * rather than recomputed — see
 * `docs/decisions/a-day-is-graded-on-distance-and-intent.md`. The read can
 * therefore never disagree with the grade: both come from the same row.
 */
final class PlanDayTool extends NoArgumentTool
{
    /** Evidence keys carrying a pace in seconds/km, given a `_formatted` twin. */
    private const array PACE_SEC_EVIDENCE_KEYS = ['pace_sec', 'ceiling_pace_sec', 'target_pace_sec', 'window_pace_sec'];

    public function __construct(
        private readonly PlannedSession $session,
        private readonly TrainingBaseline $baseline,
        /** Km actually run on this date, or null while nothing has been logged. */
        private readonly ?float $completedKm = null,
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
            .'On a credited day, intent is the deterministic verdict on whether the session did '
            ."the job it was written for: hit, missed, too_hard, or unknown when the day's data "
            .'cannot tell — never guess a stronger verdict than this. intent_evidence carries the '
            .'numbers behind it (a pace ends in _sec with a matching _formatted twin; quote only '
            .'the formatted one). intent is absent on a rest or race day, or one with no intent to '
            .'judge. eased_from means readiness eased the day: session_type and distance_km are the '
            .'eased session the athlete is actually doing, and eased_from names the session it '
            .'replaced (with its distance only when that moved). Describe the eased session as the '
            .'day, and the replaced one only as what it was eased from.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $baselineData = $this->baseline->forUser($this->session->user, Carbon::today());
        $effective = EffectiveSession::of(
            $this->session,
            PlanRenderer::coreKmForSession($this->session, $baselineData['long_run_km'], $baselineData['long_run_cap_km'], $baselineData['self_scaled']),
        );
        $easedFrom = $effective->easedFromForNarration();

        return [
            'date' => $this->session->date->toDateString(),
            'session_type' => $effective->sessionType->value,
            'phase' => $this->session->phase->value,
            'distance_km' => $effective->coreKm,
            ...($easedFrom === null ? [] : ['eased_from' => $easedFrom]),
            'skipped' => $this->session->skipped,
            // Absent rather than null on an ungraded day: a key that is always
            // there teaches the model the day is over even when it is not.
            ...($this->session->status->isCredited() ? [
                'status' => $this->session->status->value,
                'completed_km' => $this->completedKm,
                'ran_anyway' => $this->session->ran_anyway,
                ...($this->session->intent_verdict === null ? [] : [
                    'intent' => $this->session->intent_verdict->value,
                    'intent_evidence' => self::formattedEvidence($this->session->intent_evidence ?? []),
                ]),
            ] : []),
        ];
    }

    /**
     * @param  array<string, int|float|string>  $evidence
     * @return array<string, int|float|string>
     */
    private static function formattedEvidence(array $evidence): array
    {
        foreach (self::PACE_SEC_EVIDENCE_KEYS as $key) {
            if (isset($evidence[$key]) && is_numeric($evidence[$key])) {
                $formattedKey = substr($key, 0, -strlen('_sec')).'_formatted';
                $evidence[$formattedKey] = PaceFormatter::format((float) $evidence[$key]);
            }
        }

        return $evidence;
    }
}
