<?php

declare(strict_types=1);

namespace App\Support\Config;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;

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

        try {
            return $config->boolean(AppConfigKey::MaintenanceEnabled);
        } catch (QueryException|PDOException $e) {
            // A missing table or an unreachable DB can't mean the flag was
            // switched on, and everything that needs the DB fails on its own
            // anyway — so treat maintenance as off rather than lock everyone
            // (including the endpoints meant to survive an outage) out. Memoise
            // it so a later direct read this request (SharedProps) doesn't repeat
            // the doomed query.
            Log::warning('maintenance.flag_unreadable', ['reason' => $e->getMessage()]);
            $config->remember(AppConfigKey::MaintenanceEnabled, false);

            return false;
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
