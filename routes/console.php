<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 00:01 local time: refresh trend caption for active users (last 7 days).
Schedule::command('ai:daily-trend')->dailyAt('00:01');

// 00:01: generate the daily briefing set (headline, suggestion, mascot voice,
// featured kartu voice, greeting) for all active users. Uses invalidate=false
// on LLM types so an earlier DispatchPostRunAnalysis run is never re-billed.
// TrendCaption is handled separately by ai:daily-trend at 00:01.
Schedule::command('ai:daily-briefing')->dailyAt('00:01');

// Monday 00:01: narrate last week's recap once per user, on final data. The
// per-ingest cascade only stages the row Pending (weekly cadence) — this is
// the single scheduled LLM call that fills it.
Schedule::command('ai:weekly-recap')->weeklyOn(1, '00:01');

// 1st of the month 05:45: same pattern for the monthly recap.
Schedule::command('ai:monthly-recap')->monthlyOn(1, '05:45');

// 00:01 daily: re-kick the earliest Pending link of every connected chain
// (weekly + monthly) per user. The recovery sweep for cost-ceiling pauses (which
// resume after dailyCost() resets at midnight) and transient link failures, so a
// stalled link never strands the rest until the next weekly/monthly run.
Schedule::command('ai:resume-chains')->dailyAt('00:01');

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

// Hourly catch-up for activity reverse-geocoding: backfills start coords from the
// summary_polyline and re-queues ResolveActivityLocationJob for any GPS run still
// missing location_resolved_at. The per-ingest dispatch is primary; this sweeps up
// transient Nominatim misses (the job no longer stamps resolved_at on a null hit)
// and any rows ingested before geo-on-ingest landed.
Schedule::command('geo:backfill-locations')->hourly()->withoutOverlapping(55);
