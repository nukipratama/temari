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
 * every prompt builder. A call made outside a narration (a manual script)
 * reads null and stays unattributed.
 *
 * Bound `scoped`, so a long-lived worker cannot carry one job's row id into the
 * next.
 *
 * A run question has no Analysis row, so it declares its own id the same way
 * through {@see self::duringRunQuestion()}.
 */
final class NarratedAnalysis
{
    private ?int $current = null;

    private ?int $currentRunQuestion = null;

    public function current(): ?int
    {
        return $this->current;
    }

    public function currentRunQuestion(): ?int
    {
        return $this->currentRunQuestion;
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

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function duringRunQuestion(int $runQuestionId, Closure $callback): mixed
    {
        $previous = $this->currentRunQuestion;
        $this->currentRunQuestion = $runQuestionId;

        try {
            return $callback();
        } finally {
            $this->currentRunQuestion = $previous;
        }
    }
}
