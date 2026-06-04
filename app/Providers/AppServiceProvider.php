<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AI\AnalysisService;
use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\VerdictTimeline;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
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

        // Singleton so `withoutDispatching()` from one caller (e.g. DemoSeedCommand)
        // also suppresses dispatches in collaborators (RunCardFactory,
        // PersonalRecords, ActivityPipeline) that get this same instance injected.
        $this->app->singleton(AnalysisService::class);
    }

    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, StravaExtendSocialite::class);

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
    }
}
