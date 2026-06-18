<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly: refresh trend caption for active users (last 7 days). Runs every hour
// (not a single dailyAt('00:01')) so a deploy/restart that crosses midnight and
// skips the 00:01 tick self-heals within the hour (the scheduler never replays
// missed ticks on its own). TrendCaption is rule-based, so a same-day re-run only
// recomputes deterministic content (no LLM call), never re-bills.
Schedule::command('ai:daily-trend')->hourly()->withoutOverlapping(55);

// Hourly: generate the daily briefing set (headline, suggestion, mascot voice,
// featured kartu voice, greeting) for all active users. Recurring (not a single
// dailyAt('00:01')) so a missed-midnight tick on deploy self-heals within the
// hour. Idempotent: requestBriefingGroup + the LLM row types use invalidate=false,
// so firstOrCreate + the Done/queued dispatch guard make a same-day re-run create
// and dispatch ONLY the still-missing types (e.g. briefing_featured_kartu_voice),
// never re-billing rows already Done for the day.
Schedule::command('ai:daily-briefing')->hourly()->withoutOverlapping(55);

// Monday 00:01: narrate last week's recap once per user, on final data. The
// per-ingest cascade only stages the row Pending (weekly cadence) — this is
// the single scheduled LLM call that fills it.
Schedule::command('ai:weekly-recap')->weeklyOn(1, '00:01');

// 1st of the month 05:45: same pattern for the monthly recap.
Schedule::command('ai:monthly-recap')->monthlyOn(1, '05:45');

// Hourly: re-kick the earliest Pending link of every connected chain (weekly +
// monthly + per-activity) per user. The recovery sweep for cost-ceiling pauses
// (which resume after dailyCost() resets at midnight) and transient link
// failures, so a stalled link never strands the rest until the next
// weekly/monthly run. Recurring (not a single dailyAt('00:01')) so a missed
// midnight tick on deploy self-heals within the hour; running more often also
// speeds the weekly/monthly + per-activity backfill to completion. Idempotent:
// it only re-kicks Pending/Failed links with invalidate=false, so a re-run is a
// clean no-op on links already advancing and never re-bills Done rows.
Schedule::command('ai:resume-chains')->hourly()->withoutOverlapping(55);

// Fallback poll behind the Strava webhook, hourly during the two running peaks
// (WIB). Bounded withoutOverlapping expiry so a strand self-releases, not 24h.
Schedule::command('strava:sync')->hourly()->between('4:00', '10:00')->withoutOverlapping(55);
Schedule::command('strava:sync')->hourly()->between('16:00', '22:00')->withoutOverlapping(55);

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
