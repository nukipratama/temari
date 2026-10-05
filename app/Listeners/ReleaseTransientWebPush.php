<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Exceptions\Notifications\TransientWebPushException;
use Illuminate\Queue\Events\JobExceptionOccurred;

/**
 * Re-queues a push the push service asked to retry later after its Retry-After,
 * capped at {@see self::MAX_RETRY_AFTER_SECONDS}, in place of the notification's
 * fixed backoff. A job on its last attempt has
 * already failed by the time this runs and is left alone.
 */
class ReleaseTransientWebPush
{
    private const int MAX_RETRY_AFTER_SECONDS = 600;

    public function handle(JobExceptionOccurred $event): void
    {
        $exception = $event->exception;
        if (! $exception instanceof TransientWebPushException || $exception->retryAfterSeconds === null) {
            return;
        }

        $job = $event->job;
        if ($job->hasFailed() || $job->isReleased() || $job->isDeleted()) {
            return;
        }

        $job->release(min($exception->retryAfterSeconds, self::MAX_RETRY_AFTER_SECONDS));
    }
}
