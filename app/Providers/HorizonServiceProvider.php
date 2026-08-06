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
        // Real enforcement happens upstream in EnsureDevtoolsAccess (HTTP Basic
        // Auth, config/horizon.php); this gate just rubber-stamps once that
        // middleware has passed. Nullable param (unused) so Laravel's Gate
        // resolves the closure for guests too — a zero-arg closure is treated
        // as guest-denying regardless of its body.
        Gate::define('viewHorizon', fn (?User $user = null): bool => true);
    }
}
