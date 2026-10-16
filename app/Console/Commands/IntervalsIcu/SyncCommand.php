<?php

declare(strict_types=1);

namespace App\Console\Commands\IntervalsIcu;

use App\Jobs\IntervalsIcu\SyncIntervalsIcuWorkoutJob;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('intervals-icu:sync')]
#[Description("Queue a sync of today's workout to intervals.icu for every athlete with a working connection")]
class SyncCommand extends Command
{
    public function handle(): int
    {
        $userIds = User::query()
            ->notDemo()
            ->whereHas('intervalsIcuConnection', fn (Builder $query): Builder => $query->whereNull('broken_at'))
            ->pluck('id');

        foreach ($userIds as $userId) {
            SyncIntervalsIcuWorkoutJob::dispatch((int) $userId);
        }

        $this->info("Queued an intervals.icu sync for {$userIds->count()} athletes.");

        return self::SUCCESS;
    }
}
