<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:seed {--with-edge-states : Also flip a few Analysis rows to pending/processing/failed, for the audits. Not for the public demo.}')]
#[Description("Seed a realistic demo dataset (no Strava HTTP) so every page renders with believable content. Idempotent: safe to re-run.")]
class DemoSeedCommand extends Command
{
    public function handle(DemoRunSeeder $seeder): int
    {
        $count = $seeder->seed(fn (string $line) => $this->line($line));

        $this->info("Demo seed complete: {$count} runs materialised.");

        if ($this->option('with-edge-states')) {
            $changed = $seeder->seedEdgeStates(fn (string $line) => $this->line($line));
            $this->info("Edge states applied: {$changed} Analysis rows.");
        }
        $this->line('Set DEMO_LOGIN_ENABLED=true in .env to expose the demo button on /login.');

        return self::SUCCESS;
    }
}
