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
        // Edge basicauth (docker/Caddyfile) is the sole gate on /horizon; this stays
        // open so Horizon's dashboard authorization always passes through. The
        // closure must accept a nullable user: Gate treats a zero-parameter closure
        // as guest-unsafe and denies unauthenticated requests before calling it,
        // which is exactly how ops hits this page (no Strava session).
        Gate::define('viewHorizon', fn (?User $user = null): bool => true);
    }
}
