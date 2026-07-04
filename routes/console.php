<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 00:01: daily kickoff for active users (last 7 days) — briefing set (headline,
// suggestion, mascot voice, featured kartu voice, greeting) + trend caption.
// Idempotent: a same-day re-run dispatches only still-missing types, never re-bills.
Schedule::command('ai:daily-briefing')->dailyAt('00:01');

// 00:05: keep the seeded demo account fresh — one modest synthetic run (~5/week)
// plus a rule-based refresh of today's briefing/greeting/trend so the demo never
// renders an empty "Belum dibaca" once the date rolls. Zero LLM tokens
// (withoutDispatching + rule-based fill), so the demo-billing exclusion holds.
Schedule::command('demo:daily-refresh')->dailyAt('00:05');

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

// Hourly self-heal sweep: re-kicks the earliest stalled AI block per user
// (weekly + monthly + per-activity chains, plus card/PR narration) — for
// cost-ceiling pauses (release at the midnight dailyCost() reset) and transient
// failures. Idempotent (invalidate=false): a no-op on blocks already advancing,
// never re-bills; Failed blocks are bounded by MAX_SELF_HEAL_ATTEMPTS then
// dead-lettered. Early-exits while generation is paused.
Schedule::command('ai:self-heal')->hourly()->withoutOverlapping(55);

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

// 03:15 daily: correct forecast-sourced weather (rainIsForecast=true) once the
// archive/reanalysis endpoint is reliable for it (a week+ old). Free HTTP, no
// LLM; a miss just leaves the row for the next run to retry. Never touches
// RunCard badges, only the weather_* columns.
Schedule::command('weather:correct-forecast')->dailyAt('03:15')->withoutOverlapping(55);

// Saturday 18:00: nudge a user whose weekly streak is live but has no run yet
// this week, while there's still time to save it before Sunday's week-close
// breaks it. Demo excluded (checked inside the command); the streak_reminders
// claim table makes a same-week re-run a no-op, not a second push.
Schedule::command('streak:remind')->weeklyOn(Carbon::SATURDAY, '18:00');
