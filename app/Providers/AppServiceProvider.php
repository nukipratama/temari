<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\VerifyDependencies;
use App\Services\AI\AnalysisService;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\VerdictTimeline;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
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
    }

    public function boot(): void
    {
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

        // Edge basicauth (docker/Caddyfile) gates these in prod. The `?Authenticatable`
        // param is what makes the gate accept guests — a zero-param closure 403s them.
        Gate::define('viewPulse', fn (?Authenticatable $user = null): bool => true);
        Gate::define('viewAiUsage', fn (?Authenticatable $user = null): bool => true);

        RateLimiter::for('analysis-trigger', function (Request $request): Limit {
            $perMinute = max(1, (int) config('ai.rate_limit_per_minute', 8));
            $key = $request->user()?->id !== null
                ? (string) $request->user()->id
                : (string) $request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });

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
        RateLimiter::for('client-errors', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
    }
}
