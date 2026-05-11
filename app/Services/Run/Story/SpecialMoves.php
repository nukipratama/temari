<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

/**
 * Library of special-move names + the signal pattern that earns each one.
 *
 * Names are Indonesian per the voice convention; English translations live
 * in the trailing comment for engineer-readability only and never reach the
 * UI. The library is ordered: the first matching rule wins, so the rarest /
 * most-prestigious moves come first.
 *
 * Inputs come from `stream_summary` (the JSON blob StreamAnalysis produces)
 * and per-run flags (PR set in this activity, weather conditions, etc.).
 */
class SpecialMoves
{
    public const DEFAULT_MOVE = 'Langkah Mantap'; // Steady Stride

    /**
     * @param  array<string, mixed>  $streamSummary
     * @param  array{distance_m?: float|null, pr_set?: bool}  $context
     */
    public function pick(array $streamSummary, array $context): string
    {
        $zonePct = is_array($streamSummary['time_in_zone_pct'] ?? null)
            ? $streamSummary['time_in_zone_pct']
            : [];
        $distribution = is_array($streamSummary['cadence_distribution_pct'] ?? null)
            ? $streamSummary['cadence_distribution_pct']
            : [];
        $negativeSplit = (bool) ($streamSummary['negative_split'] ?? false);
        $cadenceDropSpm = (float) ($streamSummary['cadence_drop_spm'] ?? 0.0);
        $distanceM = (float) ($context['distance_m'] ?? 0.0);
        $prSet = (bool) ($context['pr_set'] ?? false);

        $z2 = (float) ($zonePct['Z2'] ?? 0.0);
        $z3 = (float) ($zonePct['Z3'] ?? 0.0);
        $z4 = (float) ($zonePct['Z4'] ?? 0.0);
        $hardShare = $z3 + $z4 + (float) ($zonePct['Z5'] ?? 0.0);

        // PR set + faster second half = a true racing-style breakthrough.
        if ($prSet && $negativeSplit) {
            return 'Pembalik Keadaan'; // Comeback
        }

        // Held sub-Z3 for a long run (≥10 km, almost no time above Z2).
        if ($distanceM >= 10_000 && $hardShare < 5.0) {
            return 'Berdarah Dingin'; // Cold Blooded
        }

        // Dominant Z3 — sustained tempo effort.
        if ($z3 > 60.0) {
            return 'Paru-paru Baja'; // Steel Lungs
        }

        // Cadence stayed >175 SPM for the bulk of the run.
        if (((float) ($distribution['>175'] ?? 0.0)) > 60.0) {
            return 'Mode Metronom'; // Metronome Mode
        }

        // Patient pacing: very Z2-heavy aerobic block.
        if ($z2 > 80.0) {
            return 'Pemburu Sabar'; // Patient Predator
        }

        // PR set early in the run (assume "first 2 km" is implicit when PR is set).
        if ($prSet) {
            return 'Tendangan Awal'; // Early Strike
        }

        // No cadence drop across the run — fatigue resistance.
        if ($cadenceDropSpm <= 1.0 && $distanceM >= 5_000) {
            return 'Tanpa Letih'; // Tireless
        }

        return self::DEFAULT_MOVE;
    }
}
