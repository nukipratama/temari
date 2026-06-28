<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use Throwable;
use App\Models\Activity;
use App\Services\AI\AnalysisService;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;

/**
 * Re-pull of a single activity: re-fetches detail + streams from Strava and
 * recomputes every derived artifact via {@see ActivityPipeline::ingest()}.
 * Shares {@see IngestActivityJob}'s rate-limit / circuit-breaker handling so a
 * resync is as resilient as the automatic ingest.
 *
 * $renarrate forces a fresh chain-head narration (an explicit, billable LLM
 * call). The manual "Resync" button opts in; the Strava update-webhook path
 * leaves it false so a trivial edit (title, gear, privacy) only refreshes data
 * and never re-bills tokens.
 */
class ResyncActivityJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    private const int RATE_LIMIT_BACKOFF_MINUTES = 5;

    private const int RATE_LIMIT_DECAY_SECONDS = 1800;

    private const int RATE_LIMIT_MAX_ATTEMPTS = 50;

    private const int RETRY_WINDOW_HOURS = 6;

    public function __construct(
        public readonly int $activityId,
        public readonly bool $renarrate = false,
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
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

    public function handle(ActivityPipeline $pipeline, AnalysisService $service): void
    {
        $activity = Activity::query()
            ->withStubs()
            ->with('user')
            ->find($this->activityId);
        if ($activity === null) {
            return;
        }

        $pipeline->ingest($activity);

        // Force a fresh narration only when asked, and only for the chain head
        // (the user's latest run). Re-narrating a mid-history run would desync the
        // later runs that quoted its old narrative, so those keep their narration
        // and just get the recomputed data (same rule the trigger endpoint
        // enforces).
        if ($this->renarrate && Activity::latestIdForUser($activity->user_id) === $activity->id) {
            $service->requestActivityGroup($activity, invalidate: true);
        }
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
