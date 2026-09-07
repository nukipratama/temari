<?php

declare(strict_types=1);

namespace App\Services\AI\Anchor;

use App\Services\Run\Metrics\StreamSummary;

/**
 * Whether an anchor names something a given run's own {@see StreamSummary}
 * actually measured. Lifted out of {@see \App\Services\AI\Narrators\RunInsightNarrator}
 * so the grammar and the resolution are testable apart from the narrator, and
 * so a second run-scoped narrator can reuse them rather than restate them.
 *
 * Resolving is not the same as being citable: a reading this class accepts may
 * still have no component drawing it, which the client decides
 * (`resources/js/lib/anchors.ts`). A claim about an undrawn reading is kept —
 * it is a true observation — and simply offers no control.
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
        };
    }

    /** The exhaustive `metric:<name>` set; any other name falls through to false. */
    private static function metricResolves(string $name, StreamSummary $summary): bool
    {
        return match ($name) {
            'decoupling' => $summary->hasDecouplingPct(),
            'hr_drift' => $summary->hrDriftBpm() !== null,
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
