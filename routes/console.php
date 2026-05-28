<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Local dev: fresh migrations + Horizon Redis clear + demo seed in one step.
if (app()->environment('local')) {
    Artisan::command('dev:fresh', function () {
        $this->call('migrate:fresh');
        $this->callSilently('horizon:clear');
        $this->newLine();
        $this->call('demo:seed', ['--fresh' => true]);
    })->purpose('migrate:fresh + horizon:clear + demo seed (local only)');
}

// 05:00 local time: refresh trend caption for active users (last 7 days).
Schedule::command('ai:daily-trend')->dailyAt('05:00');
