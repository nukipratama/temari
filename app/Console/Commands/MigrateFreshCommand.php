<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Database\Console\Migrations\FreshCommand;
use Override;

/**
 * Extends the built-in migrate:fresh to also clear Horizon's Redis state and
 * re-seed demo data automatically in the local environment.
 *
 * Runs: migrate:fresh → horizon:clear → demo:seed --fresh (local only)
 */
class MigrateFreshCommand extends FreshCommand
{
    #[Override]
    public function handle(): int
    {
        $exitCode = parent::handle();

        if ($exitCode !== self::SUCCESS) {
            return $exitCode;
        }

        // Clear Horizon's Redis metadata — stale pending/completed job references
        // become invalid after tables are dropped and IDs reset.
        $this->callSilently('horizon:clear');

        $this->newLine();
        $this->call('demo:seed', ['--fresh' => true]);

        return self::SUCCESS;
    }
}
