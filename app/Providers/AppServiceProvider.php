<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
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

        Gate::define('viewPulse', fn (?User $user = null): bool => $this->app->environment('local')
            || ($user
                && ($id = $user->stravaConnection?->strava_athlete_id)
                && in_array((int) $id, config('devtools.admin_strava_ids'), true)));
    }
}
