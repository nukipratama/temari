<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Run\Metrics\ResolveRunBaselineAction;
use App\Events\ActivityIngested;
use App\Http\Middleware\EnsureDevtoolsAccess;
use App\Listeners\DispatchPostRunAnalysis;
use App\Listeners\RecordScheduledTaskRun;
use App\Listeners\VerifyDependencies;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\NarrationOrigin;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\VerdictTimeline;
use App\Support\Config\AppConfig;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Override;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Strava\StravaExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(VerdictNarrator::class, VerdictTimeline::class);

        // Scoped: one shared instance per request/command (so `withoutDispatching()`
        // reaches collaborators), flushed by Octane between requests.
        $this->app->scoped(AnalysisService::class);
        $this->app->scoped(NarrationOrigin::class);

        // Scoped so its per-request/per-job read memo collapses repeat lookups but
        // stays fresh across requests and queue jobs (DB remains source of truth).
        $this->app->scoped(AppConfig::class);

        // Scoped for the same reason, and specifically so the run-insight toolbox
        // reads the 28-day baseline once. RelativeEffort and RecentBaselineTool
        // both ask for it with identical arguments, and the memo can only collapse
        // that if they are handed the same instance — otherwise the container
        // builds RelativeEffort its own ResolveRunBaselineAction and the window is
        // scanned twice.
        $this->app->scoped(ResolveRunBaselineAction::class);
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // The analytics-schema migrations live outside the default path (they
        // run via `--path` against the `analytics` connection in dev/prod). In
        // testing the analytics connection shares the default test DB, so load
        // them into the normal migrate run that RefreshDatabase performs.
        if ($this->app->environment('testing')) {
            $this->loadMigrationsFrom(database_path('migrations/analytics'));
        }

        Event::listen(SocialiteWasCalled::class, StravaExtendSocialite::class);

        // Deepen the `/up` health route to fail when MySQL or Redis is unreachable.
        Event::listen(DiagnosingHealth::class, VerifyDependencies::class);

        // Post-ingest AI analysis fan-out runs in its own queued job.
        Event::listen(ActivityIngested::class, DispatchPostRunAnalysis::class);

        // Scheduler heartbeat: record every command's last run for the Pulse card.
        Event::listen(ScheduledTaskFinished::class, [RecordScheduledTaskRun::class, 'finished']);
        Event::listen(ScheduledTaskFailed::class, [RecordScheduledTaskRun::class, 'failed']);

        // Real enforcement happens upstream in EnsureDevtoolsAccess (HTTP Basic
        // Auth); this gate just rubber-stamps once that middleware has passed
        // (Pulse's own Authorize middleware checks it). Nullable param (unused)
        // so Laravel's Gate resolves the closure for guests too — a zero-arg
        // closure is treated as guest-denying regardless of its body.
        Gate::define('viewPulse', fn (?User $user = null): bool => true);

        // Livewire's update endpoint (Pulse ops cards) is devtools-gated and
        // throttled like the other devtools routes; `web` + the header guard
        // are appended by setUpdateRoute.
        Livewire::setUpdateRoute(fn ($handle, string $updatePath): Route => RouteFacade::post($updatePath, $handle)
            ->middleware(['web', 'throttle:60,1', EnsureDevtoolsAccess::class]));

        RateLimiter::for('analysis-trigger', function (Request $request): Limit {
            $perMinute = max(1, (int) config('ai.rate_limit_per_minute', 8));
            $key = $request->user()?->id !== null
                ? (string) $request->user()->id
                : (string) $request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });

        // "Ask about this run". Every accepted question is a full agent run, so
        // this is tighter than the analysis trigger, which mostly no-ops on an
        // already-Done row. A rate limit, not a cost cap: app-wide spend is the
        // daily_cost_ceiling's job.
        RateLimiter::for('run-question', function (Request $request): Limit {
            $perMinute = max(1, (int) config('ai.run_question_rate_limit_per_minute', 4));
            $key = $request->user()?->id !== null
                ? (string) $request->user()->id
                : (string) $request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });

        // Account creation path. Anyone on the internet can reach it, and the
        // callback spends a Strava token exchange plus, on a first connect, a
        // whole history backfill against the app-wide Strava budget. IP-keyed
        // because there is no user to key by until it succeeds.
        RateLimiter::for('strava-oauth', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));

        // "Sync now" button. The orchestrator lock already de-dupes overlapping
        // syncs; this just keeps an impatient tapper from flooding the queue.
        RateLimiter::for('strava-sync', function (Request $request): Limit {
            $key = $request->user()?->id !== null
                ? (string) $request->user()->id
                : (string) $request->ip();

            return Limit::perMinute(2)->by($key);
        });

        // Client-error telemetry sink. IP-keyed so a single misbehaving browser
        // (error loop) can't flood the logs.
        RateLimiter::for('client-errors', fn (Request $request): Limit => Limit::perMinute(10)->by((string) $request->ip()));
    }
}
