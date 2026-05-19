<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Devtools;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => Devtools::isAdmin($user));
    }
}
