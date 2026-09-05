<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\RecapPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:monthly-recap')]
#[Description('Kick off the connected monthly-recap chain: narrate every completed month whose recap is not Done, oldest first (demo excluded)')]
class MonthlyRecapCommand extends Command
{
    public function handle(KickoffMonthlyRecaps $kickoff): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        ['dispatched' => $dispatched, 'rule_based' => $ruleFilled] = $kickoff();

        $lastClosedMonth = RecapPeriod::lastClosedMonth();
        $this->info("Dispatched monthly recap for {$dispatched} months ({$ruleFilled} filled rule-based) through {$lastClosedMonth}.");

        return self::SUCCESS;
    }
}
