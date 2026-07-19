<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\MaintainerAlerter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deploy:alert {reason : Why the deploy failed}')]
#[Description('Push a maintainer alert that a prod deploy failed. Called best-effort from the CI deploy job on failure; a no-op when Telegram is unconfigured.')]
class DeployAlertCommand extends Command
{
    public function handle(MaintainerAlerter $alerter): int
    {
        $alerter->deployFailed((string) $this->argument('reason'));

        return self::SUCCESS;
    }
}
