<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

/**
 * Raised when a narrator's validator rejects the answer and the rewrite it
 * asked for too. Extends {@see UnavailableException} so an uncaught path stays
 * terminal, while the distinct type lets the activity group degrade the
 * rejected block to rule-based narration instead of re-billing a retry that
 * would most likely be rejected again.
 */
class RejectedOutputException extends UnavailableException
{
}
