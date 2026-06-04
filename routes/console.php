<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fresh migrations + Horizon Redis clear + demo seed in one step.
// Requires --force outside local environment, mirroring migrate:fresh behaviour.
Artisan::command('dev:fresh {--force : Required outside the local environment}', function () {
    if (! app()->environment('local') && ! $this->option('force')) {
        $this->error('Use --force to run dev:fresh outside the local environment.');

        return self::FAILURE;
    }

    $this->call('migrate:fresh', app()->environment('local') ? [] : ['--force' => true]);
    $this->call('horizon:clear', app()->environment('local') ? [] : ['--force' => true]);

    // The analytics schema is deliberately NOT freshed — that's the whole point
    // (ai_token_usages cost history survives). Just apply any pending analytics
    // migrations. Needs the schema to exist first (`make analytics-init` once).
    try {
        $this->call('migrate', [
            '--database' => 'analytics',
            '--path' => 'database/migrations/analytics',
            '--force' => true,
        ]);
    } catch (Throwable) {
        $this->warn('Skipped analytics migrate (run `make analytics-init` once to create the schema).');
    }

    $this->newLine();
    $this->call('demo:seed', ['--fresh' => true]);
})->purpose('migrate:fresh + horizon:clear + demo seed (--force required outside local)');

// 05:00 local time: refresh trend caption for active users (last 7 days).
Schedule::command('ai:daily-trend')->dailyAt('05:00');

// Hourly fallback poll behind the Strava webhook: catches activities Strava
// failed to push (delivery is best-effort). Ingest only — it leans on the
// "already done" idempotency guards in AnalysisService / AnalyzeGroupJob, so it
// never re-bills LLM narration for activities already analyzed.
Schedule::command('strava:sync')->hourly()->withoutOverlapping();
