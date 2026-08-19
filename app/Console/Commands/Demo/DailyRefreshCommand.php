<?php

declare(strict_types=1);

namespace App\Console\Commands\Demo;

use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:daily-refresh')]
#[Description("Keep the seeded demo account fresh: one modest synthetic run (~5/week) plus a rule-based refresh of today's narration, so it never goes stale or goes empty. Zero LLM tokens.")]
class DailyRefreshCommand extends Command
{
    public function handle(DemoRunSeeder $seeder): int
    {
        $seeder->refreshToday(fn (string $line) => $this->line($line));

        $this->info('Demo daily refresh complete.');

        return self::SUCCESS;
    }
}
