<?php

declare(strict_types=1);

namespace App\Support\Config;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;

/** Laravel maintenance driver backed by the durable `app_config` flag. */
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
        try {
            return $this->config()->boolean(AppConfigKey::MaintenanceEnabled);
        } catch (QueryException|PDOException $e) {
            Log::warning('maintenance.flag_unreadable', ['reason' => $e->getMessage()]);

            return true;
        }
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
