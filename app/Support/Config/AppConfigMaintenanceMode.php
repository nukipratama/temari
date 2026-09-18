<?php

declare(strict_types=1);

namespace App\Support\Config;

use Illuminate\Contracts\Foundation\MaintenanceMode;

/**
 * Laravel maintenance driver backed by the durable `app_config` flag, so the
 * Pulse toggle and `artisan down` / `up` flip one value that the web, Horizon
 * and scheduler containers all read.
 */
class AppConfigMaintenanceMode implements MaintenanceMode
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function activate(array $payload): void
    {
        $this->config()->set(AppConfigKey::MaintenanceEnabled, true);
    }

    public function deactivate(): void
    {
        $this->config()->set(AppConfigKey::MaintenanceEnabled, false);
    }

    public function active(): bool
    {
        // A paused queue worker never resets its scoped instances, so a memoised
        // read would keep it paused after maintenance lifts.
        $config = $this->config();
        $config->forget(AppConfigKey::MaintenanceEnabled);

        return $config->boolean(AppConfigKey::MaintenanceEnabled);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }

    private function config(): AppConfig
    {
        return app(AppConfig::class);
    }
}
