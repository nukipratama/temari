<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('horizon:recommend-ai-processes')]
#[Description('Prints a recommended HORIZON_AI_PROCESSES value from the current athlete count. Never auto-scales; the owner sets the env by hand.')]
class HorizonRecommendAiProcessesCommand extends Command
{
    /** Lower bound: today's fixed value, kept even at zero/one athletes. */
    private const int MIN_PROCESSES = 2;

    /** Upper bound: a guess pending real multi-athlete Monday-burst data, not a measurement. */
    private const int MAX_PROCESSES = 6;

    /** Athletes per recommended process, ceil-divided. */
    private const int ATHLETES_PER_PROCESS = 5;

    public function handle(): int
    {
        $athletes = User::query()->notDemo()->count();
        $recommended = self::recommend($athletes);
        $configured = (int) config('horizon.ai_processes');

        $this->info("Athletes: {$athletes}");
        $this->info("Recommended HORIZON_AI_PROCESSES: {$recommended}");
        $this->info("Currently configured: {$configured}");

        if ($recommended !== $configured) {
            $this->warn("Configured value differs from the recommendation. The homelab host is shared with prod, so this is not applied automatically - set HORIZON_AI_PROCESSES={$recommended} by hand if you agree.");
        }

        return self::SUCCESS;
    }

    public static function recommend(int $athletes): int
    {
        $raw = (int) ceil($athletes / self::ATHLETES_PER_PROCESS);

        return max(self::MIN_PROCESSES, min(self::MAX_PROCESSES, $raw));
    }
}
