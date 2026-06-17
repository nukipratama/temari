<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    #[Override]
    protected function gate(): void
    {
        // Defense in depth: edge basicauth (docker/Caddyfile) is the first gate on
        // /horizon, this app-layer allow-list is the second so a Caddy misconfig
        // can't expose it. Predicate is shared with the Pulse / ai-usage gates.
        Gate::define('viewHorizon', fn (?Authenticatable $user = null): bool => AppServiceProvider::isOpsUser($user));
    }
}
