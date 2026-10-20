<?php

declare(strict_types=1);

namespace App\Services\AI\Anchor;

use App\Services\Run\Metrics\StreamSummary;

/**
 * Whether an anchor names something a given run's own {@see StreamSummary} actually measured.
 *
 * Resolving is not the same as being citable: the client decides whether a component draws a
 * reading (`resources/js/lib/anchors.ts`), and a claim about an undrawn reading is kept without a
 * control.
 */
final class RunAnchorResolver
{
    public function resolves(string $anchor, StreamSummary $summary): bool
    {
        $parsed = AnchorKind::parse($anchor);
        if ($parsed === null) {
            return false;
        }

        [$kind, $value] = $parsed;

        return match ($kind) {
            AnchorKind::Split => count($summary->perKm() ?? []) >= (int) $value,
            AnchorKind::Zone => $summary->zonePct() !== [] || $summary->zoneMinutes() !== null,
            AnchorKind::Metric => self::metricResolves($value, $summary),
            // The prescribed session belongs to a date, not to a run's stream.
            AnchorKind::Session => false,
        };
    }

    /** The exhaustive `metric:<name>` set; any other name falls through to false. */
    private static function metricResolves(string $name, StreamSummary $summary): bool
    {
        return match ($name) {
            'decoupling' => $summary->hasSteadyEffortDecouplingPct(),
            'hr_drift' => $summary->steadyEffortHrDriftBpm() !== null,
            'cadence_drop' => $summary->cadenceDropSpm() !== null,
            'pace_variability' => $summary->paceVariabilitySec() !== null,
            'grade' => $summary->maxGradePct() !== null,
            'gap_pace' => $summary->gapPace() !== null,
            // A computed bool (true or false) is a real reading; only the
            // absence of the key at all means this run never measured it.
            'negative_split' => $summary->negativeSplit() !== null,
            default => false,
        };
    }
}
