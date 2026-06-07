<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use RuntimeException;
use Throwable;

/**
 * Raised when an Azure OpenAI call fails for a transient reason (HTTP 429
 * rate-limit, 5xx server error, or a connection/timeout). Unlike
 * {@see UnavailableException} (terminal: bad schema, malformed JSON, permanent
 * 4xx), this is retryable: the queue worker should re-attempt under its
 * configured `$tries`/`$backoff` instead of marking the row failed.
 *
 * `$retryAfterSeconds` carries Azure's `Retry-After` hint when the upstream
 * supplies one, so the job can release itself with that delay.
 */
class TransientUpstreamException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
