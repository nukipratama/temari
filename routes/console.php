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
    $this->callSilently('horizon:clear');
    $this->newLine();
    $this->call('demo:seed', ['--fresh' => true]);
})->purpose('migrate:fresh + horizon:clear + demo seed (--force required outside local)');

// 05:00 local time: refresh trend caption for active users (last 7 days).
Schedule::command('ai:daily-trend')->dailyAt('05:00');
