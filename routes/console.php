<?php

declare(strict_types=1);

use App\Services\AI\MaintainerAlerter;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Push a maintainer Telegram alert when an AI-critical scheduled command fails,
// so a broken command surfaces as a push instead of silently taking down
// background narration/recovery for days. Only fires for commands that actually
// run — a scheduler that never runs anything is covered by the heartbeat below.
$alertOnFailure = static fn (Event $event, string $command): Event => $event->onFailure(static function () use ($command): void {
    app(MaintainerAlerter::class)->schedulerFailed($command);
});

// Every minute: stamp a liveness timestamp on the durable Redis so the scheduler
// container's healthcheck (`schedule:heartbeat --check`) can tell a live
// schedule:work from a dead or wedged one. Deliberately no withoutOverlapping —
// the write is one idempotent SETEX, and the scheduler mutex would take a lock
// on the evictable cache store every minute for nothing. No $alertOnFailure
// either: while Redis is down this would fail every 60s, and the alerter's own
// cooldown is Redis-backed.
Schedule::command('schedule:heartbeat')->everyMinute()->onOneServer();

// 00:01: daily kickoff for active users (last 7 days) — one briefing_mascot_voice
// row each. The headline/suggestion/greeting/trend-caption types this once also
// dispatched are retired (see UserEraser's retired-type list), and the featured
// card voice went with W2's sweep.
// Idempotent: a same-day re-run dispatches only still-missing types, never re-bills.
// withoutOverlapping/onOneServer rationale: docs/architecture/scheduler.md.
$alertOnFailure(Schedule::command('ai:daily-briefing')->dailyAt('00:01')->withoutOverlapping(30)->onOneServer(), 'ai:daily-briefing');

// 00:05: keep the seeded demo account fresh — one modest synthetic run (~5/week)
// plus a rule-based refresh of today's briefing/greeting/trend so the demo never
// renders an empty block once the date rolls. Zero LLM tokens
// (withoutDispatching + rule-based fill), so the demo-billing exclusion holds.
Schedule::command('demo:daily-refresh')->dailyAt('00:05')->withoutOverlapping(10)->onOneServer();

// Monday 00:01: narrate last week's recap once per user, on final data. The
// per-ingest cascade only stages the row Pending (weekly cadence) — this is
// the single scheduled LLM call that fills it.
$alertOnFailure(Schedule::command('ai:weekly-recap')->weeklyOn(1, '00:01')->withoutOverlapping(30)->onOneServer(), 'ai:weekly-recap');

// Monday 00:05: refresh the Profile-page persona summary + Temari voice once a
// week, just after the recap. These two have no per-run cadence, so this is
// their only auto-refresh; persona self-throttles per ISO week and the voice is
// invalidated weekly. Demo excluded. Mid-week freshness stays on "Reread".
Schedule::command('ai:weekly-profile')->weeklyOn(1, '00:05')->withoutOverlapping(20)->onOneServer();

// 00:02 daily, ahead of plan:regenerate: retire a race the athlete has already
// run. `completed_at` was only ever stamped by RaceController::store()
// superseding one goal with another, so an unreplaced race stayed active
// forever and the periodizer kept planning against a day in the past.
$alertOnFailure(Schedule::command('plan:close-finished-races')->dailyAt('00:02')->withoutOverlapping(10)->onOneServer(), 'plan:close-finished-races');

// 00:03 daily: judge every user's Planned rows that just became past —
// status/compliance_score/ran_anyway written once, never re-touched.
// Idempotent by construction (only ever selects still-Planned rows), so it's
// also the one-time backfill mechanism for existing historical rows after
// this feature ships — no separate backfill command needed. Must run before
// plan:regenerate (00:07), which reads last week's average score on Mondays.
$alertOnFailure(Schedule::command('plan:score-compliance')->dailyAt('00:03')->withoutOverlapping(20)->onOneServer(), 'plan:score-compliance');

// Monday 00:07: regenerate every user's plan today-forward against their
// current fitness/race state. Past weeks and pinned rows are never touched.
// On-demand regeneration is also available from the Plan page.
//
// The periodizer is deterministic and free, but this command is NOT LLM-free:
// it then calls PlanNarrationRequester::requestForCurrentWeek() per non-demo
// user, dispatching plan_day_voice x7 and plan_week_voice with invalidate
// gated per row on whether its content fingerprint actually changed (a
// session that regenerated into the same shape is left alone) plus an
// idempotent plan_season_voice. Up to 9 rows per user per week. See
// docs/architecture/llm-triggers.md.
$alertOnFailure(Schedule::command('plan:regenerate')->weeklyOn(1, '00:07')->withoutOverlapping(45)->onOneServer(), 'plan:regenerate');

// 1st of the month 00:10: HR zones change rarely, so a monthly sweep is enough
// (also piggybacks the per-connect SyncZonesJob dispatch). Skips manual-source
// profiles and connections lacking `profile:read_all`.
Schedule::command('strava:sync-zones')->monthlyOn(1, '00:10')->withoutOverlapping(55)->onOneServer();

// 1st of the month 05:45: same pattern for the monthly recap.
$alertOnFailure(Schedule::command('ai:monthly-recap')->monthlyOn(1, '05:45')->withoutOverlapping(30)->onOneServer(), 'ai:monthly-recap');

// Trends tab's "Temari's read", one range at a time, tiered by how often
// each range's own numbers actually move (not just to spread out cost):
// 30d changes day to day, 90d barely moves over a few days, 12mo barely
// moves over a week. Scheduled + cached like every other narrator — never
// generated live per page view. See TREND_READ_RANGES.
$alertOnFailure(Schedule::command('ai:trend-read 30d')->dailyAt('06:00')->withoutOverlapping(20)->onOneServer(), 'ai:trend-read 30d');
// `*/3` on the day-of-month field, not an every-3-days interval: it resets
// on the 1st, so the gap between runs is 1-2 days at each month boundary.
$alertOnFailure(Schedule::command('ai:trend-read 90d')->cron('0 6 */3 * *')->withoutOverlapping(20)->onOneServer(), 'ai:trend-read 90d');
$alertOnFailure(Schedule::command('ai:trend-read 12mo')->weeklyOn(1, '06:00')->withoutOverlapping(20)->onOneServer(), 'ai:trend-read 12mo');

// Hourly self-heal sweep: re-kicks the earliest stalled AI block per user
// (weekly + monthly + per-activity chains, plus card/PR narration) — for
// cost-ceiling pauses (release at the midnight dailyCost() reset) and transient
// failures. Idempotent (invalidate=false): a no-op on blocks already advancing,
// never re-bills; Failed blocks are bounded by MAX_SELF_HEAL_ATTEMPTS then
// dead-lettered. Early-exits while generation is paused.
$alertOnFailure(Schedule::command('ai:self-heal')->hourly()->withoutOverlapping(55)->onOneServer(), 'ai:self-heal');

// Hourly catch-up sweep, the creation-side companion to ai:self-heal: recreates
// the kickoff rows a scheduler outage across 00:01 (or across Monday 00:01/00:05)
// never created, since every self-heal family starts from a row that already
// exists. Upsert-only under AnalysisService::withoutDispatching() — an existing
// row of any status is untouched, nothing is queued and nothing is billed, so
// unlike ai:self-heal it deliberately keeps running while generation is paused.
// See docs/decisions/kickoff-catch-up-is-upsert-only.md.
$alertOnFailure(Schedule::command('ai:catch-up')->hourly()->withoutOverlapping(55)->onOneServer(), 'ai:catch-up');

// 02:20 daily: prune failed_jobs older than 7 days. Most entries are superseded
// dupes of the same Analysis rows (which are the real source of truth), so the
// table just bloats and reads as an alarming unexplained count during triage.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('02:20')->withoutOverlapping(15)->onOneServer();

// 02:25 daily: prune the analytics-connection metering tables (ai_token_usages,
// strava_sync_logs), which had no retention at all before this. 90 days keeps
// enough history for cost/rate-limit triage without unbounded growth.
Schedule::command('analytics:prune')->dailyAt('02:25')->withoutOverlapping(15)->onOneServer();

// Fallback poll behind the Strava webhook. Hourly around the clock rather than
// only across the two running peaks: the old window left a five-hour overnight
// gap in which a missed webhook went unnoticed, and one read per connected user
// per run is a rounding error against the shared pool at this size. It is the
// one scheduled job whose cost scales with user count, so revisit the cadence
// long before the athlete cap does — see docs/decisions/background-hydration-drain.md.
// Bounded withoutOverlapping so a strand self-releases, not 24h.
Schedule::command('strava:sync')->hourly()->withoutOverlapping(55)->onOneServer();

// Every 5 minutes: paced drain of pending activity stubs (the Strava rate-limit
// pacer). Its input is strava:sync stubs + detail-fetch retries (webhook activities
// self-dispatch their own ingest); batching keeps a backlog from 429-storming Strava.
Schedule::command('strava:ingest')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Every 15 minutes: drain the summary-only backlog newest-first, so an imported
// history converges on splits, TRIMP, PRs, cards and narration instead of waiting
// for someone to open each run. Sized from the background read headroom, so it
// yields the whole pool to live ingest rather than competing with it, and the
// cadence cannot overspend — a tick only ever takes what the buckets allow.
//
// Matches the 15-minute read bucket's own decay window. Hourly left recovered
// headroom idle for most of an hour, which bit hardest against the drain's known
// "headroom is measured at dispatch, not spent at dispatch" behaviour: a tick that
// fires into a still-draining bucket queues only a handful of runs, and used to
// wait an hour for its next chance. See docs/decisions/background-hydration-drain.md.
Schedule::command('strava:hydrate-backlog')->everyFifteenMinutes()->withoutOverlapping(14)->onOneServer();

// Hourly catch-up for activity reverse-geocoding: backfills start coords from the
// summary_polyline and re-queues ResolveActivityLocationJob for any GPS run still
// missing location_resolved_at. Primary dispatch is per-ingest; this sweeps up
// transient Nominatim misses and rows ingested before geo-on-ingest landed.
Schedule::command('geo:backfill-locations')->hourly()->withoutOverlapping(55)->onOneServer();

// 03:15 daily: correct forecast-sourced weather (rainIsForecast=true) once the
// archive/reanalysis endpoint is reliable for it (a week+ old). Free HTTP, no
// LLM; a miss just leaves the row for the next run to retry. Never touches
// RunCard badges, only the weather_* columns.
Schedule::command('weather:correct-forecast')->dailyAt('03:15')->withoutOverlapping(55)->onOneServer();

// 03:30 daily: re-fetch weather for runs with coords but a null weather_temp_c,
// left behind by a transient Open-Meteo blip during ingest. The documented
// self-repair path so a weather gap closes itself instead of persisting forever.
// Rows older than the forecast window route to the archive endpoint automatically,
// so a daily sweep is enough. Free HTTP, no LLM.
Schedule::command('weather:backfill')->dailyAt('03:30')->withoutOverlapping(55)->onOneServer();

// 03:45 daily: grow-forward VDOT/pace-consistency history, one row per user
// per day. Every user, not just recently-active ones — a rest week still
// needs a row, or Trends' "not enough history yet" state never resolves for
// someone who's resting. No backfill on purpose; a day with no row has no
// history, it isn't retroactively derived from old runs.
Schedule::command('trend:snapshot-daily')->dailyAt('03:45')->withoutOverlapping(55)->onOneServer();

// Saturday 18:00: nudge a user whose weekly streak is live but has no run yet
// this week, while there's still time to save it before Sunday's week-close
// breaks it. Demo excluded (checked inside the command); the streak_reminders
// claim table makes a same-week re-run a no-op, not a second push.
Schedule::command('streak:remind')->weeklyOn(Carbon::SATURDAY, '18:00')->withoutOverlapping(15)->onOneServer();

// Monday 00:00: settle the week that just closed — mint a rest token every 4th
// streak week, or spend one to forgive a runless week. Must run before
// ai:weekly-recap (00:01), which reads consecutiveWeekStreak() and would
// otherwise narrate a streak that this command is about to restore. No LLM and
// no Strava call.
Schedule::command('streak:settle')->weeklyOn(1, '00:00')->withoutOverlapping(20)->onOneServer();
