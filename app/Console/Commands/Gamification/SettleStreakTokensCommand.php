<?php

declare(strict_types=1);

namespace App\Console\Commands\Gamification;

use App\Jobs\Gamification\SettleStreakWeeksJob;
use App\Services\Gamification\StreakSettlementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('streak:settle')]
#[Description('Settle the week that just closed against each user\'s weekly streak: mint a rest token, or spend one to forgive a runless week')]
class SettleStreakTokensCommand extends Command
{
    public function handle(StreakSettlementService $settlement): int
    {
        $users = $settlement->unsettledUsers()->pluck('id');

        foreach ($users as $userId) {
            SettleStreakWeeksJob::dispatch((int) $userId)->afterCommit();
        }

        $this->info("Queued streak settlement for {$users->count()} users.");

        return self::SUCCESS;
    }
}
