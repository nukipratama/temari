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

// 05:15: generate the daily briefing set (headline, suggestion, mascot voice,
// featured kartu voice, greeting) for all active users. Uses invalidate=false
// on LLM types so an earlier DispatchPostRunAnalysis run is never re-billed.
// TrendCaption is handled separately by ai:daily-trend at 05:00.
Schedule::command('ai:daily-briefing')->dailyAt('05:15');

// Sunday 04:30: refresh the Azure OpenAI per-1M token price map from the public
// Retail Prices API into the cache. On failure it logs and keeps the config seed,
// so a missed refresh never blanks out cost reporting.
Schedule::command('ai:refresh-azure-prices')->weeklyOn(0, '04:30');

// Monday 05:30: narrate last week's recap once per user, on final data. The
// per-ingest cascade only stages the row Pending (weekly cadence) — this is
// the single scheduled LLM call that fills it.
Schedule::command('ai:weekly-recap')->weeklyOn(1, '05:30');

// 1st of the month 05:45: same pattern for the monthly recap.
Schedule::command('ai:monthly-recap')->monthlyOn(1, '05:45');

// Hourly fallback poll behind the Strava webhook: catches activities Strava
// failed to push (delivery is best-effort). Ingest only — it leans on the
// "already done" idempotency guards in AnalysisService / AnalyzeGroupJob, so it
// never re-bills LLM narration for activities already analyzed.
// withoutOverlapping carries an EXPIRY so a lock stranded by a mid-run container
// restart (deploy) or crash self-releases within one cycle instead of the 24h
// default — a strand once silently halted the drain until manual intervention.
Schedule::command('strava:sync')->hourly()->withoutOverlapping(55);

// Every 5 minutes: drain a small batch of pending activity stubs into the
// ingest pipeline. Stubs are inserted by strava:sync / webhooks without an
// immediate per-activity dispatch; this drainer paces them so a backlog never
// thundering-herds Strava into a 429 storm.
Schedule::command('strava:ingest')->everyFiveMinutes()->withoutOverlapping(10);
