<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 05:00 local time: refresh trend caption for active users (last 7 days).
Schedule::command('ai:daily-trend')->dailyAt('05:00');

Schedule::command('telescope:prune --hours=72')->daily();
