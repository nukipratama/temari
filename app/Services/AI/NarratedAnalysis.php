<?php

declare(strict_types=1);

namespace App\Services\AI;

use Closure;

/**
 * The Analysis row currently being narrated, held for the length of one
 * generation so the metered usage row can be joined back to the block it paid
 * for.
 *
 * The same seam {@see NarrationOrigin} uses, and for the same reason: threading
 * an id through every narrator signature would push a metering concern into
 * every prompt builder. A call made outside a narration (a run question, a
 * manual script) reads null and stays unattributed.
 *
 * Bound `scoped`, so a long-lived worker cannot carry one job's row id into the
 * next.
 */
final class NarratedAnalysis
{
    private ?int $current = null;

    public function current(): ?int
    {
        return $this->current;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function during(?int $analysisId, Closure $callback): mixed
    {
        $previous = $this->current;
        $this->current = $analysisId;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}
