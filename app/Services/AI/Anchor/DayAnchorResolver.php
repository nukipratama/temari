<?php

declare(strict_types=1);

namespace App\Services\AI\Anchor;

use App\Models\PlannedSession;

/**
 * Whether an anchor names something a given day actually has. The day-scoped
 * counterpart to {@see RunAnchorResolver}, for the narrators that speak about a
 * date rather than a run.
 *
 * Takes the row rather than querying for it, so the grammar stays testable
 * without a database and the caller keeps the one query it already makes.
 */
final class DayAnchorResolver
{
    public function resolves(string $anchor, ?PlannedSession $today): bool
    {
        $parsed = AnchorKind::parse($anchor);
        if ($parsed === null) {
            return false;
        }

        [$kind] = $parsed;

        return match ($kind) {
            AnchorKind::Session => $today !== null,
            // Split, zone and metric describe one run's own stream, which a
            // day does not have. A briefing citing `split:4` is pointing at
            // something this page never draws.
            AnchorKind::Split, AnchorKind::Zone, AnchorKind::Metric => false,
        };
    }
}
