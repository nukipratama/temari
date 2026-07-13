<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    #[Override]
    protected function gate(): void
    {
        // /horizon requires a logged-in maintainer (`is_admin` per Strava
        // account); edge basicauth (docker/Caddyfile) stays as defense-in-depth.
        // The closure accepts a nullable user so a guest resolves to false rather
        // than erroring.
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->is_admin === true);
    }
}
