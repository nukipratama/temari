<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\KickoffCatchUp;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:catch-up')]
#[Description('Hourly upsert-only sweep: create the kickoff rows a missed 00:01 / Monday scheduler minute never created')]
class CatchUpCommand extends Command
{
    public function handle(KickoffCatchUp $catchUp): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $created = $catchUp();

        $this->info("Created {$created} missing kickoff rows.");

        return self::SUCCESS;
    }
}
