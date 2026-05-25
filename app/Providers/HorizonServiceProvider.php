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
        // Edge basicauth (docker/Caddyfile) gates /horizon in prod. The `?Authenticatable`
        // param is what makes the gate accept guests — a zero-param closure 403s them.
        Gate::define('viewHorizon', fn (?Authenticatable $user = null): bool => true);
    }
}
