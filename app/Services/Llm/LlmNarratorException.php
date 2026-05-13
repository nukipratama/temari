<?php

declare(strict_types=1);

namespace App\Services\Llm;

use RuntimeException;

/**
 * Tagged exception so FallbackBriefingNarrator (and verdict counterpart)
 * can distinguish LLM-call failures from real bugs without catching
 * every Throwable indiscriminately.
 */
class LlmNarratorException extends RuntimeException
{
}
