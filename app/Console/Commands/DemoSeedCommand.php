<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:seed {--fresh : Wipe prior demo data before seeding}')]
#[Description("Seed a realistic demo dataset (no Strava HTTP) so every page renders with believable content.")]
class DemoSeedCommand extends Command
{
    public function handle(DemoRunSeeder $seeder): int
    {
        $fresh = (bool) $this->option('fresh');

        $count = $seeder->seed($fresh, fn (string $line) => $this->line($line));

        $this->info("Demo seed complete: {$count} runs materialised.");
        $this->line('Set DEMO_LOGIN_ENABLED=true in .env to expose the demo button on /login.');

        return self::SUCCESS;
    }
}
