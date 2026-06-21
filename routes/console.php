<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 00:01: daily kickoff for active users (last 7 days) — briefing set (headline,
// suggestion, mascot voice, featured kartu voice, greeting) + trend caption.
// Idempotent: a same-day re-run dispatches only still-missing types, never re-bills.
Schedule::command('ai:daily-briefing')->dailyAt('00:01');

// Monday 00:01: narrate last week's recap once per user, on final data. The
// per-ingest cascade only stages the row Pending (weekly cadence) — this is
// the single scheduled LLM call that fills it.
Schedule::command('ai:weekly-recap')->weeklyOn(1, '00:01');

// Monday 00:05: refresh the Aku-page persona summary + Kata Temari voice once a
// week, just after the recap. These two have no per-run cadence, so this is
// their only auto-refresh; persona self-throttles per ISO week and the voice is
// invalidated weekly. Demo excluded. Mid-week freshness stays on "Baca ulang".
Schedule::command('ai:weekly-profile')->weeklyOn(1, '00:05');

// 1st of the month 05:45: same pattern for the monthly recap.
Schedule::command('ai:monthly-recap')->monthlyOn(1, '05:45');

// Hourly recovery sweep: re-kicks the earliest Pending/Failed link of every
// connected chain (weekly + monthly + per-activity) per user — for cost-ceiling
// pauses (release at the midnight dailyCost() reset) and transient link failures.
// Idempotent (invalidate=false): a no-op on links already advancing, never re-bills.
Schedule::command('ai:resume-chains')->hourly()->withoutOverlapping(55);

// Fallback poll behind the Strava webhook, hourly across the two running peaks
// (WIB: 04-10 and 16-22). Bounded withoutOverlapping so a strand self-releases, not 24h.
Schedule::command('strava:sync')->cron('0 4-10,16-22 * * *')->withoutOverlapping(55);

// Every 5 minutes: paced drain of pending activity stubs (the Strava rate-limit
// pacer). Its input is strava:sync stubs + detail-fetch retries (webhook activities
// self-dispatch their own ingest); batching keeps a backlog from 429-storming Strava.
Schedule::command('strava:ingest')->everyFiveMinutes()->withoutOverlapping(10);

// Hourly catch-up for activity reverse-geocoding: backfills start coords from the
// summary_polyline and re-queues ResolveActivityLocationJob for any GPS run still
// missing location_resolved_at. Primary dispatch is per-ingest; this sweeps up
// transient Nominatim misses and rows ingested before geo-on-ingest landed.
Schedule::command('geo:backfill-locations')->hourly()->withoutOverlapping(55);
