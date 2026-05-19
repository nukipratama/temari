<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Run\Story\Contracts\VerdictNarrator;
use App\Services\Run\Story\VerdictTimeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
    }

    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, StravaExtendSocialite::class);

        // Access control for these dashboards lives at the Caddy edge in prod
        // (basicauth on /horizon, /pulse, /ai-usage). The gates stay permissive
        // because by the time a request reaches PHP, the edge has already let
        // it through — re-gating in-app would just block local dev.
        Gate::define('viewPulse', fn (): bool => true);
        Gate::define('viewAiUsage', fn (): bool => true);
    }
}
