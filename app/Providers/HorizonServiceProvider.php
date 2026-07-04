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
        // Edge basicauth (docker/Caddyfile) is the sole gate on /horizon; this stays
        // open so Horizon's dashboard authorization always passes through.
        Gate::define('viewHorizon', fn (): bool => true);
    }
}
