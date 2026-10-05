<?php

declare(strict_types=1);

namespace App\Exceptions\Notifications;

use RuntimeException;

/**
 * Raised when no push subscription accepted a notification and at least one push
 * service failed transiently (429, 5xx, or a network error), so the queued
 * notification should be retried, after `$retryAfterSeconds` when the push
 * service sent a Retry-After.
 */
class TransientWebPushException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $retryAfterSeconds = null)
    {
        parent::__construct($message);
    }
}
