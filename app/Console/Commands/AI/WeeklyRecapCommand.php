<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\KickoffWeeklyRecaps;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\RecapPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:weekly-recap')]
#[Description('Kick off the connected weekly-recap chain: narrate every completed week whose recap is not Done, oldest first')]
class WeeklyRecapCommand extends Command
{
    public function handle(KickoffWeeklyRecaps $kickoff): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        ['dispatched' => $dispatched, 'rule_based' => $ruleFilled, 'deferred' => $deferred] = $kickoff();

        $lastWeekEnding = RecapPeriod::lastClosedWeekEnding();
        $this->info("Dispatched weekly recap for {$dispatched} snapshots ({$ruleFilled} filled rule-based, {$deferred} deferred for hydration) through week ending {$lastWeekEnding}.");

        return self::SUCCESS;
    }
}
