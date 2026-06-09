<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use Throwable;
use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;

class IngestActivityJob implements ShouldQueue
{
    use Queueable;

    /**
     * Genuine (non rate-limit) failures get a small budget before the job is
     * marked failed. Strava 429s are absorbed by the ThrottlesExceptions
     * middleware and never count against this.
     */
    public int $maxExceptions = 3;

    /**
     * Minutes the throttle middleware waits before re-attempting after a 429,
     * scaling with how many times it has tripped within the decay window.
     */
    private const int RATE_LIMIT_BACKOFF_MINUTES = 5;

    /**
     * Window (seconds) over which throttle hits decay; one Strava daily-limit
     * reset comfortably fits inside it.
     */
    private const int RATE_LIMIT_DECAY_SECONDS = 1800;

    /**
     * Upper bound on how many 429 backoffs the middleware will absorb before
     * it stops re-queueing for this job class.
     */
    private const int RATE_LIMIT_MAX_ATTEMPTS = 50;

    /**
     * Hard time bound for the job. A rate-limit backoff loop runs until this
     * deadline rather than against a fixed attempt count, so a busy Strava
     * bucket never trips MaxAttemptsExceeded.
     */
    private const int RETRY_WINDOW_HOURS = 6;

    public function __construct(public readonly int $activityId)
    {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            // Both a 429 and an open circuit mean "back off and retry later"
            // rather than burn the failure budget — the throttle re-queues with a
            // delay that comfortably outlasts the breaker cooldown.
            (new ThrottlesExceptions(self::RATE_LIMIT_MAX_ATTEMPTS, self::RATE_LIMIT_DECAY_SECONDS))
                ->when(fn (Throwable $e): bool => $e instanceof StravaRateLimitedException
                    || $e instanceof StravaCircuitOpenException)
                ->backoff(self::RATE_LIMIT_BACKOFF_MINUTES)
                ->by('strava-ingest'),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    public function handle(ActivityPipeline $pipeline): void
    {
        $activity = Activity::query()
            ->withStubs()
            ->with('user.stravaConnection')
            ->find($this->activityId);
        if ($activity === null) {
            return;
        }

        $pipeline->ingest($activity);
    }

    /**
     * Once the retry budget / window is exhausted the job lands in failed_jobs.
     * Log which activity got stuck and why, so a stalled ingest is traceable
     * without digging through the queue payload.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('strava.ingest.failed', [
            'activity_id' => $this->activityId,
            'exception' => $exception::class,
            'reason' => $exception->getMessage(),
        ]);
    }
}
