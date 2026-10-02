<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AI\MaintainerAlerter;
use App\Support\NewExceptionLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('exceptions:digest')]
#[Description('Push the exception fingerprints first seen since the last digest to every admin on Telegram')]
class ExceptionDigestCommand extends Command
{
    public function handle(MaintainerAlerter $alerter): int
    {
        $entries = NewExceptionLedger::pull();

        if ($entries !== []) {
            $alerter->exceptionDigest($entries);
        }

        $this->info('Sent '.count($entries).' new exception fingerprints.');

        return self::SUCCESS;
    }
}
