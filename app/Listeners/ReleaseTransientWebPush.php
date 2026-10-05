<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Exceptions\Notifications\TransientWebPushException;
use Illuminate\Queue\Events\JobExceptionOccurred;

/**
 * Re-queues a push the push service asked to retry later after its Retry-After,
 * in place of the notification's fixed backoff. A job on its last attempt has
 * already failed by the time this runs and is left alone.
 */
class ReleaseTransientWebPush
{
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

        $job->release($exception->retryAfterSeconds);
    }
}
