<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

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

        if ($prSet && $negativeSplit) {
            return 'Pembalik Keadaan'; // Comeback
        }

        if ($distanceM >= 10_000 && $hardShare < 5.0) {
            return 'Berdarah Dingin'; // Cold Blooded
        }

        if ($z3 > 60.0) {
            return 'Paru-paru Baja'; // Steel Lungs
        }

        if (((float) ($distribution['>175'] ?? 0.0)) > 60.0) {
            return 'Metronom'; // Metronome Mode
        }

        if ($z2 > 80.0) {
            return 'Pemburu Sabar'; // Patient Predator
        }

        if ($prSet) {
            return 'Tendangan Awal'; // Early Strike
        }

        if ($cadenceDropSpm <= 1.0 && $distanceM >= 5_000) {
            return 'Tanpa Letih'; // Tireless
        }

        return self::DEFAULT_MOVE;
    }
}
