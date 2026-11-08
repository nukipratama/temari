<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AI\Analysis;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after commit when an LLM narration started during a fresh connect's early pass but
 * finished after the history drain emptied, so the row must be narrated once more.
 */
final readonly class EarlyPassStraddled
{
    use Dispatchable;

    public function __construct(public Analysis $analysis)
    {
    }
}
