<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

/**
 * Raised when Azure's content filter rejects a Responses call: either an HTTP 400
 * whose error code is `content_filter` (input-side) or a 200 marked incomplete
 * with a `content_filter` reason (output-side). Extends {@see UnavailableException}
 * so any uncaught path still behaves as terminal (row Failed, no queue retry).
 *
 * Unlike a generic terminal failure it is input-driven: the distinct type lets
 * the caller strip continuity context and retry once, and lets the row/group job
 * degrade to rule-based narration instead of dead-lettering.
 */
class ContentFilterException extends UnavailableException
{
}
