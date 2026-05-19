<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Devtools;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;
use Override;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(fn (IncomingEntry $entry) => $isLocal
            || $entry->isReportableException()
            || $entry->isFailedRequest()
            || $entry->isFailedJob()
            || $entry->isScheduledTask()
            || $entry->hasMonitoredTag());
    }

    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    #[Override]
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn ($user = null): bool => Devtools::isAdmin($user));
    }
}
