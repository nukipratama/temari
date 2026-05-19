<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => $user
            && ($id = $user->stravaConnection?->strava_athlete_id)
            && in_array((int) $id, config('devtools.admin_strava_ids'), true));
    }
}
