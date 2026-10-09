<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use Throwable;
use App\Enums\StravaReadPriority;
use App\Enums\StravaReadSource;
use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

/**
 * Genuine (non rate-limit) failures get a small budget before the job is
 * marked failed. Strava 429s are absorbed by the ThrottlesExceptions
 * middleware and never count against this.
 */
#[MaxExceptions(3)]
class IngestActivityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

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

    /**
     * Hold the uniqueness lock for the whole retry window so the hourly ingest
     * drain never re-dispatches a still-throttled stub as a duplicate job.
     */
    public int $uniqueFor = self::RETRY_WINDOW_HOURS * 3600;

    public function __construct(
        public readonly int $activityId,
        public readonly StravaReadPriority $priority = StravaReadPriority::Live,
        public readonly StravaReadSource $source = StravaReadSource::IngestSweep,
    ) {
    }

    public function uniqueId(): string
    {
        return (string) $this->activityId;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            // Both a 429 and an open circuit mean "back off and retry later"
            // rather than burn the failure budget — the throttle re-queues with a
            // delay that comfortably outlasts the breaker cooldown. The key is
            // per priority tier: ThrottlesExceptions releases every job sharing a
            // key once the circuit trips, so one key would let a backed-off
            // browsing burst hold back live ingest it never competed with.
            new ThrottlesExceptions(self::RATE_LIMIT_MAX_ATTEMPTS, self::RATE_LIMIT_DECAY_SECONDS)
                ->when(fn (Throwable $e): bool => $e instanceof StravaRateLimitedException
                    || $e instanceof StravaCircuitOpenException)
                ->backoff(self::RATE_LIMIT_BACKOFF_MINUTES)
                ->by($this->priority->throttleKey()),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    public function handle(ActivityPipeline $pipeline): void
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Ingest);

        $activity = Activity::query()
            ->withStubs()
            ->with('user.stravaConnection')
            ->find($this->activityId);
        if ($activity === null) {
            return;
        }

        $pipeline->ingest($activity, $this->source, $this->priority);
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
