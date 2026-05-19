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
        // Edge basicauth (docker/Caddyfile) gates /horizon in prod.
        Gate::define('viewHorizon', fn (): bool => true);
    }
}
