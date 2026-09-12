<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PlannedSession;
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
 * A readiness-eased day is the exception, and the only figure here read
 * from the row: `clamped_km` is a decision already taken, recorded by
 * {@see \App\Services\Run\Plan\RestClampRecorder}, and it is what the card
 * shows. The clamp still never reaches
 * {@see \App\Services\AI\MaterialFingerprint} — see
 * `docs/decisions/the-clamp-explains-itself.md`.
 */
final class PlanDayTool extends NoArgumentTool
{
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
            .'eased true means readiness cut the day back: distance_km is the smaller figure the '
            .'athlete was actually asked for, not the session it replaced.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $baselineData = $this->baseline->forUser($this->session->user, Carbon::today());
        $coreKm = PlanRenderer::coreKmForSession($this->session, $baselineData['long_run_km']);

        // A readiness-eased day was told to run less, and that smaller figure is
        // what the card shows and what the athlete is being asked for. Reading
        // past it leaves the blurb describing a session that was called off.
        $easedKm = $this->session->clamped_km;

        return [
            'date' => $this->session->date->toDateString(),
            'session_type' => $this->session->session_type->value,
            'phase' => $this->session->phase->value,
            'distance_km' => $easedKm ?? $coreKm,
            ...($easedKm === null ? [] : ['eased' => true]),
            'skipped' => $this->session->skipped,
            // Absent rather than null on an ungraded day: a key that is always
            // there teaches the model the day is over even when it is not.
            ...($this->session->status->isCredited() ? [
                'status' => $this->session->status->value,
                'completed_km' => $this->completedKm,
                'ran_anyway' => $this->session->ran_anyway,
            ] : []),
        ];
    }
}
