<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use Throwable;
use App\Enums\StravaReadPriority;
use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;

/**
 * Re-pull of a single activity: re-fetches detail + streams from Strava and
 * recomputes every derived artifact via {@see ActivityPipeline::ingest()}.
 * Shares {@see IngestActivityJob}'s rate-limit / circuit-breaker and
 * uniqueness handling so a resync is as resilient as the automatic ingest.
 */
class ResyncActivityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    private const int RATE_LIMIT_BACKOFF_MINUTES = 5;

    private const int RATE_LIMIT_DECAY_SECONDS = 1800;

    private const int RATE_LIMIT_MAX_ATTEMPTS = 50;

    private const int RETRY_WINDOW_HOURS = 6;

    public int $uniqueFor = self::RETRY_WINDOW_HOURS * 3600;

    public function __construct(
        public readonly int $activityId,
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
            new ThrottlesExceptions(self::RATE_LIMIT_MAX_ATTEMPTS, self::RATE_LIMIT_DECAY_SECONDS)
                ->when(fn (Throwable $e): bool => $e instanceof StravaRateLimitedException
                    || $e instanceof StravaCircuitOpenException)
                ->backoff(self::RATE_LIMIT_BACKOFF_MINUTES)
                ->by(StravaReadPriority::Live->throttleKey()),
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
            ->find($this->activityId);
        if ($activity === null) {
            return;
        }

        $pipeline->ingest($activity);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('strava.resync.failed', [
            'activity_id' => $this->activityId,
            'exception' => $exception::class,
            'reason' => $exception->getMessage(),
        ]);
    }
}
