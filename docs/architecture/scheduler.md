---
title: Scheduler hygiene — overlap safety, single-host, ordering, cadence
description: Every Schedule::command entry is overlap-safe and single-host by an unstated one-container invariant; entries due in the same minute run serially, so strava:sync runs at :07; every run lands in an append-only run log; the Monday window's hard dependencies are chained and retry hourly until they succeed; the numeric derivation behind four previously-qualitative cadences; and 30 days of production runtimes next to each lock TTL
tags: [architecture, scheduler]
status: living
reviewed: 2026-11-06
code_refs:
  - routes/console.php
  - app/Console/Commands/Notifications/RecoverStaleNotificationDeliveriesCommand.php
  - app/Console/Commands/Notifications/ReleaseHeldNotificationsCommand.php
  - app/Services/Notifications/NotificationDeliveryClaim.php
  - app/Console/SchedulerChain.php
  - app/Listeners/RecordScheduledTaskRun.php
  - app/Models/Analytics/ScheduledTaskRunLog.php
  - app/Livewire/Pulse/SchedulerHealth.php
  - app/Console/Commands/MondayCheckCommand.php
  - app/Services/Gamification/StreakSettlementService.php
  - compose.prod.yaml
  - config/strava.php
  - config/cache.php
---

# Scheduler hygiene

[routes/console.php](../../routes/console.php) registers every scheduled command. This note covers
six things: why every event carries both `withoutOverlapping()` and `onOneServer()`, the order
entries run in within one minute's tick, the per-run log every entry writes, how the Monday window's entries are chained and caught up after a miss,
the numeric basis for four cadences that were previously justified only qualitatively, and 30
days of production runtimes next to each lock TTL.

## Overlap safety and single-host

[compose.prod.yaml](../../compose.prod.yaml) defines exactly **one** `scheduler` service. That is
the only reason two schedulers have never double-run a command — an unstated invariant, not an
enforced one. `onOneServer()` makes that invariant free to hold today and load-bearing the moment
the container ever scales past one; `withoutOverlapping()` guards the orthogonal case of the same
container's *next* tick starting before the current run has finished. Every event in
`routes/console.php` carries `withoutOverlapping()` explicitly and `onOneServer()` through
`Schedule::alwaysOnOneServer()` at the top of the file, with one deliberate exception:

`schedule:heartbeat` skips `withoutOverlapping()` on purpose — the write is one idempotent `SETEX`,
so a mutex taken every minute would guard nothing (see its comment in `routes/console.php`). It still takes `onOneServer()`, since a second scheduler container should
not double-write a heartbeat any more than it should double-run anything else.

`onOneServer()` and `withoutOverlapping()` both key their lock through the app's default cache
store ([config/cache.php](../../config/cache.php) — `redis` in prod and in
tests via `phpunit.xml`, `database` from `.env.example` locally). The `redis` store takes its locks
on its `lock_connection`, the durable `default` connection, so an eviction on the allkeys-lru
`cache` instance never drops a held mutex. `onOneServer()` additionally
needs a store every scheduler container can reach, which `redis` is in prod.

### Lock TTL and onOneServer, per command

The production column is `ScheduledTaskRunLog::stats()` read on 2026-11-06, covering the run log
from 2026-10-06 to 2026-11-06: runs, then the p50 / p95 / max runtime of successful runs. Across
every entry in that window there were no failed runs and no killed runs, and the only skips are the
closed-gate ticks of the gated entries. See "Reading the production numbers" below for what they
say about each TTL.

| command | cadence | withoutOverlapping | onOneServer | why this TTL | production, 2026-10-06 to 2026-11-06: runs, p50 / p95 / max |
|---|---|---|---|---|---|
| `notifications:recover-deliveries` | every 5 minutes | 10 | yes | one indexed scan of stale pending claims; Telegram claims become terminal, while web-push retries stay discoverable until a worker claims a new version | 8,620 runs, 0.67 / 0.91 / 1.17s |
| `notifications:release-held` | every 5 minutes | 10 | yes | a no-op inside quiet hours (22:00-04:00); outside them, queues each held row in id order, one small transaction per row. A row whose payload can no longer be restored (it fails to unserialize, or unserializes into something other than a Notification) is logged and dropped, a failure to dispatch a restored one leaves its row held for the next tick, and either makes the run exit non-zero. Every five minutes rather than once at 04:00, so a deploy across 04:00 delays the release by one tick ([[quiet-hours-hold-every-notification]]) | 8,620 runs, 0.66 / 0.92 / 1.26s |
| `schedule:heartbeat` | every minute | — (deliberate) | yes | one idempotent `SETEX`; a lock would cost more than the write itself | not logged — the heartbeat stays out of the run log |
| `ai:daily-briefing` | daily 00:01 | 30 | yes | per-user dispatch loop over active (7d) users; 30 min is generous headroom before the next day's run | 30 runs, 1.03 / 1.36 / 1.46s |
| `demo:daily-refresh` | daily 00:13 | 10 | yes | single demo user, one synthetic run + rule-based fill | 30 runs, 3.01 / 3.56 / 3.65s |
| `plan:close-finished-races` | daily 00:04, retried hourly at :04 until it succeeds that day | 10 | yes | one bulk `UPDATE ... WHERE race_date < today` | 30 runs, 0.67 / 0.97 / 0.98s (688 gate skips) |
| `plan:score-compliance` | daily 00:09, retried hourly at :09 until it succeeds that day | 20 | yes | bounded by `--limit=500` users, one scoring pass each | 30 runs, 1.07 / 1.49 / 1.62s (688 gate skips) |
| `ai:weekly-recap` | Mon 00:16 | 30 | yes | per-user dispatch loop, same shape as `ai:daily-briefing` | 4 runs, 0.81 / 0.98 / 0.98s |
| `ai:weekly-profile` | Mon 00:21 | 20 | yes | per-active-user dispatch loop, lighter than the recap (one row type) | 4 runs, 0.61 / 1.07 / 1.07s |
| `plan:regenerate` | Mon 00:26, retried every Monday hour at :26 until one run succeeds that week | 45 | yes | heaviest entry: `Periodizer::regenerate()` per user | 4 runs, 1.61 / 1.78 / 1.78s (92 gate skips) |
| `strava:sync-zones` | monthly 00:10 | 55 (unchanged) | yes | already guarded pre-DF-1 | 1 run, 1.69 / 1.69 / 1.69s |
| `ai:trend-read 7d` | daily 06:00 | 20 | yes | one narrator pass across active users | 30 runs, 0.92 / 1.26 / 1.37s |
| `ai:self-heal` | hourly | 55 (unchanged) | yes | already guarded pre-DF-1 | 717 runs, 0.72 / 1.01 / 1.27s |
| `ai:catch-up` | hourly | 55 (unchanged) | yes | already guarded pre-DF-1 | 717 runs, 0.97 / 1.29 / 2.02s |
| `ai:spend-digest` | daily 21:00 | 10 | yes | one grouped read of today's `ai_token_usages` rows, then one Telegram push to every admin | 30 runs, 0.72 / 0.96 / 1.00s |
| `exceptions:digest` | daily 21:00 | 10 | yes | pulls the exception ledger once and pushes only when a fingerprint is new; silent on a quiet day | 30 runs, 0.58 / 0.87 / 0.95s |
| `queue:prune-failed` | daily 02:20 | 15 | yes | one `DELETE` on `failed_jobs` | 30 runs, 0.70 / 0.97 / 1.00s |
| `analytics:prune` | daily 02:25 | 15 | yes | six chunked `DELETE`s (1,000 rows per statement, repeated until a short chunk) — five on the `analytics` connection, one on `analysis_versions` | 30 runs, 0.63 / 0.87 / 0.95s |
| `model:prune TelegramUpdateReceipt` | daily 02:30 | 15 | yes | delete Telegram update receipts older than 7 days | 30 runs, 0.63 / 0.88 / 0.97s |
| `model:prune TelegramLinkTokenUse` | daily 02:31 | 15 | yes | delete spent link-token claims after token expiry | 30 runs, 0.78 / 0.90 / 0.96s |
| `notifications:prune-push-subscriptions` | daily 02:35 | 15 | yes | one `SELECT DISTINCT` and one `DELETE` on `push_subscriptions` for rows unseen for 60 days | 30 runs, 0.68 / 0.96 / 0.97s |
| `strava:sync` / `strava:ingest` / `strava:hydrate-backlog` | see `routes/console.php` | 55/10/14 (unchanged) | yes | already guarded pre-DF-1 | `strava:sync` 718 runs, 15.52 / 17.76 / 22.88s; `strava:ingest` 8,620 runs, 0.69 / 0.97 / 1.16s; `strava:hydrate-backlog` 2,872 runs, 0.69 / 0.97 / 1.10s |
| `geo:backfill-locations` / `weather:correct-forecast` / `weather:backfill` | see `routes/console.php` | 55/55/55 (unchanged) | yes | already guarded pre-DF-1 | `geo:backfill-locations` 717 runs, 0.73 / 0.99 / 1.24s; `weather:correct-forecast` 29 runs, 1.55 / 2.07 / 2.23s; `weather:backfill` 30 runs, 0.66 / 1.01 / 3.20s |
| `trend:snapshot-daily` | daily 03:45 | 55 (unchanged) | yes | queues durable closed-date recovery in 365-day chunks; `--days=N` remains the focused mode | 30 runs, 0.73 / 0.97 / 0.99s |
| `race:remind` | hourly 18:00-21:00, every tick after the first send a no-op | 15 | yes | one race-goal sweep, same shape and cost as `streak:remind` | 117 runs, 0.70 / 0.97 / 1.05s |
| `race:ask-outcome` | hourly 09:00-21:00, every tick after the first ask a no-op | 15 | yes | one indexed sweep of races dated yesterday with a pending outcome, one notify each | 368 runs, 0.69 / 0.96 / 1.19s |
| `plan:settle-time-trials` | daily 09:05 | 15 | yes | one indexed sweep of the last week's unsettled time-trial rows for non-demo athletes, at most one evidence write or one notify each; a settled row is never selected again | 30 runs, 0.76 / 0.97 / 0.99s |
| `fitness:notify-improvement` | daily 10:00 | 30 | yes | one estimate per non-demo athlete with a run analysed since their `users.fitness_checked_at` stamp (never-checked athletes included; a rise held back by the weekly interval is not stamped), plus on Mondays every athlete not checked in the last 7 days, so a rise that matures over rest weeks is still noted; each due athlete is selected once per run, and the stamp keeps a same-day re-run from checking them again. A new or cancelled race goal checks the athlete at once through the queued `CheckFitnessImprovementJob`, so a run before the next scan is not folded into the new distance's baseline. Each estimate is compared against their last noted VDOT, one notify for each improvement of at least 0.5 a week apart; the first run only records baselines; one athlete's failure is reported and alerted once at the end, the rest are still checked | 29 runs, 0.77 / 1.09 / 1.13s |
| `briefing:morning-push` | every 15 min | 14 | yes | one sweep, an hour before each athlete's usual start or at their chosen time, that keeps only the athletes due this tick, then loads their briefings and deliveries in one query each; sends only, generates nothing | 2,872 runs, 0.69 / 0.97 / 1.26s |
| `intervals-icu:sync` | every 15 min | 14 | yes | one indexed read of working intervals.icu connections, one queued job per athlete; the HTTP calls run on the queue, not in the tick | 1,991 runs, 0.68 / 0.97 / 1.20s |
| `streak:remind` | Sat 18:00 | 15 | yes | one push-eligibility sweep; one athlete's failure is reported and alerted once at the end, the rest still get their nudge | 4 runs, 0.70 / 0.82 / 0.82s |
| `streak:settle` | hourly | 20 | yes | queues chronological per-user settlement for the athletes still behind or marked dirty; one query when nobody is | 717 runs, 0.68 / 0.91 / 1.20s |
| `schedule:monday-check` | Mon 06:00 | 10 | yes | one indexed count plus the chain flags, at most one alert per week | 4 runs, 0.88 / 1.05 / 1.05s |
| `horizon:snapshot` | every 5 minutes | 4 | yes | one Redis snapshot of Horizon's per-queue and per-job metrics for its Metrics tab, trimmed by `horizon.metrics.trim_snapshots` | 8,091 runs, 0.68 / 0.95 / 1.29s |
| `schedule:check-late` | every 5 minutes | 4 | yes | one heartbeat-table read plus the chain flags, at most one alert per entry per incident | 8,620 runs, 0.69 / 0.96 / 1.99s |
| `RetryOrphanedStravaGrantReleasesJob` (queued job) | daily 02:40 | 30 | yes | retries the Strava release of grants whose local connection is gone or revoked, one call per orphaned grant | 30 runs, 0.05 / 0.13 / 0.17s |

Values marked "unchanged" already had `withoutOverlapping()` before this pass and keep their
existing TTL; only `onOneServer()` was added to those.

**Reading the production numbers.** Every entry except two finishes within about 2 seconds at
p95, which is mostly `artisan`'s own bootstrap. `demo:daily-refresh` (3.7s max) and `strava:sync`
(22.9s max, polling Strava inline once per athlete) are the slowest, and both stay far inside their
10- and 55-minute locks. No p95 comes near its `withoutOverlapping()` lifetime, so every TTL stays as
sized. Per-user loops such as `ai:daily-briefing`, `ai:weekly-recap`, `plan:score-compliance`,
`plan:regenerate`, `ai:trend-read 7d` and `strava:sync` grow with the athlete count, so their TTL
headroom is what covers a larger athlete base.

## Tick order

`schedule:work` starts one `schedule:run` a minute, and that run executes every entry due in that
minute one after another, in registration order, because no entry uses `runInBackground()`. A slow
entry therefore delays every entry registered after it in the same minute, though not the next
minute's run. The :00 tick is the busy one: `ai:self-heal`, `ai:catch-up`, `geo:backfill-locations`
and `streak:settle` every hour, the five- and fifteen-minute entries (`notifications:*`,
`strava:ingest`, `strava:hydrate-backlog`, `briefing:morning-push`, `intervals-icu:sync`, `schedule:check-late`), and on
their hours `ai:trend-read 7d`, `schedule:monday-check`, `race:ask-outcome`,
`fitness:notify-improvement`, `race:remind`, `streak:remind` and the 21:00 digests.

`strava:sync` runs at **:07** (`hourlyAt(7)`), a minute no other entry uses and not a multiple of 5,
so it shares its tick only with `schedule:heartbeat`, which is registered first. Its runtime grows
with the athlete count, because it polls Strava inline once per connected athlete (17.9 s at three
athletes). At :00 it held back everything registered after it, including `streak:settle` on Monday
and the briefing push. It stays inline rather than queuing one job per athlete, because that would
lose the command's aggregate failed/recovered alert unless a job batch and a `job_batches` table
were added. It also does not use `runInBackground()`, because the scheduler's 120 s
`stop_grace_period` (see [[deployment]]) covers an in-flight `schedule:run` but not a background
child. The stubs it finds are drained by `strava:ingest` at :10.

## Run history

Two tables record what the scheduler did:

- `scheduled_task_runs` (default connection) is the heartbeat: one upserted row per command with
  its last status and runtime, `last_run_at` for every run and `last_success_at` for the last
  run that did not fail ([ScheduledTaskRun](../../app/Models/ScheduledTaskRun.php)). Its `isStale()` is
  what reads an entry as late.
- `scheduled_task_run_logs` (`analytics` connection) is append-only, one row per run
  ([ScheduledTaskRunLog](../../app/Models/Analytics/ScheduledTaskRunLog.php)): command,
  `started_at`, `finished_at`, `runtime_ms`, `status` (`running`, `ok`, `failed`, `skipped`),
  `exit_code` and `skipped_reason` (`gate`, `paused`, `overlapping`).

[RecordScheduledTaskRun](../../app/Listeners/RecordScheduledTaskRun.php) writes both. It opens a
`running` log row on `ScheduledTaskStarting` and closes it on `ScheduledTaskFinished` (ok, or
failed on a non-zero exit) or `ScheduledTaskFailed`. A run that found its `withoutOverlapping()`
lock taken still fires Starting and Finished, so it closes as an `overlapping` skip, and it does
not refresh the heartbeat: a lock that stays jammed leaves `last_run_at` behind, so the entry reads
as late. A closed
`->when()` gate fires only `ScheduledTaskSkipped`, which writes a closed `gate` row, or a `paused`
row while `schedule:pause` is on. `schedule:heartbeat` stays out of the log: at one run a minute
it would be most of the rows and says nothing the heartbeat key does not.

No process writes a "killed" row. A run whose process dies (SIGKILL, OOM, a container restart)
simply stays `running`, and it reads as **killed** once it has been open longer than its entry's
`withoutOverlapping()` lifetime, which is the longest a run is expected to hold the lock (a day for
a command off the schedule). `analytics:prune` deletes log rows older than 90 days.

`ScheduledTaskRunLog::stats()` is the one query over the log: per command over 30 days, the runs,
failures, skips and killed runs, and the nearest-rank p50/p95/max runtime of successful runs. The
`/pulse` Scheduler card shows that line under each entry, and shows the entry itself as `killed`
while its latest run is.

## Alerts

Every entry wrapped in `$alertOnFailure` in [routes/console.php](../../routes/console.php) pages
once per incident through [MaintainerAlerter](../../app/Services/Ops/MaintainerAlerter.php). The
first failure pages. The entry then stays silent while it keeps failing, except for one repeat page
per 24 hours, and its next success sends one "recovered" line. The open incident is a key on the
`durable` store, so a cache eviction cannot re-page it. `athletesFailed()`, which a per-athlete
loop (`ai:daily-briefing`, `plan:score-compliance`, `streak:remind`, `fitness:notify-improvement`)
sends when it skipped athletes after errors, follows the same rule per command: a run with no
failed athlete closes the incident.

`schedule:check-late` runs every 5 minutes and pages once per incident for each entry that is
late, with one "back on time" line when it is not
([SchedulerChain::isLate()](../../app/Console/SchedulerChain.php)):

- A gated entry (`SchedulerChain::DAILY_GATED`, `WEEKLY_GATED`) is late once its previous whole
  day or ISO week passed without a success and the current one has none yet, read from the chain
  flags. Its closed gate skips every tick in between, so its heartbeat says nothing. A gate that
  never opens still goes late.
- Every other entry is late once `ScheduledTaskRun::isStale()` says so: about twice its cadence
  since its last success, or since its row was first recorded while it has never succeeded. A run
  that fails or exits non-zero does not advance `last_success_at`, so an entry that keeps failing
  goes late whether or not it is wrapped in `$alertOnFailure`, and its page says it has kept
  failing since then. A lock skip does not refresh the heartbeat, so a jammed lock goes late too.

Three more sources raise alerts:

- `strava:sync` and `strava:sync-zones` report athletes whose sync threw through `athletesFailed()`,
  under the same per-command incident as `ai:daily-briefing` and `plan:score-compliance`. A run
  with no failed athlete closes it. A revoked connection is not a failure.
- `geo:backfill-locations` and `weather:backfill` count the non-demo runs still missing their
  location or weather between 48 hours and 7 days after ingest. A count above zero opens the
  incident, with no repeat page, and zero closes it. A miss the next run repairs never reaches 48
  hours, and an older row ages out of the window, so an incident can always close and rows already
  in production never open one at deploy.
- `RetryOrphanedStravaGrantReleasesJob`'s `failed()` hook pages like an entry failure, repeating
  once per 24 hours, and its next completed run closes the incident.

Alerts raised by the scheduler itself (an entry's failure, lateness and recovery, `athletesFailed()`
from a per-athlete loop, and the backfill gaps) go to Telegram inline with a 5-second timeout
instead of through the queued `SendMaintainerAlertJob`, so a dead or paused Horizon cannot silence
them. Every other maintainer alert stays queued, including the orphaned-grant job's, which already
runs on a worker. If the cache errors while reading an incident or cooldown key, the alert is
sent anyway, because a duplicate page is better than silence. A recovery or back-on-time line is
not, since every success and every sweep checks for one.

## The Monday window: ordering and catch-up

The Monday entries are spread over `00:00`-`00:26`, with each gap sized to the dependency it
protects, and every entry that a later one depends on retries until it succeeds:

| command | time | must run after | why |
|---|---|---|---|
| `streak:settle` | 00:00, then every hour of every day | — | settles the week that just closed; each run queues only the athletes still behind or marked dirty, so a new or dirty athlete is settled within the hour all week |
| `ai:daily-briefing` | 00:01 | — | daily cadence, unrelated to the Monday chain |
| `plan:close-finished-races` | 00:04, then hourly at :04 until it succeeds that day | — | must itself finish before `plan:regenerate` |
| `plan:score-compliance` | 00:09, then hourly at :09 until it succeeds that day | — | must itself finish before `plan:regenerate` |
| `demo:daily-refresh` | 00:13 | — | independent, single demo user, zero LLM cost |
| `ai:weekly-recap` | 00:16 | — | reads the week's own snapshot and plan, never the streak; `ai:self-heal` usually narrates it at 00:00 already |
| `ai:weekly-profile` | 00:21 | per athlete: that athlete's settlement | the profile voice quotes the settled weekly streak, so each athlete's voice waits for their own settlement (below) |
| `plan:regenerate` | 00:26, then every Monday hour at :26 until one run succeeds | `plan:close-finished-races`, `plan:score-compliance` (both done today) | regenerates today-forward off the newly retired races (`CloseFinishedRacesCommand`'s own docblock: an unretired race made `PhaseSchedule::forRace()` throw) and reads last week's average compliance score |
| `schedule:monday-check` | 06:00 | — | one maintainer alert if anything above is still behind (below) |

**The plan chain.** [SchedulerChain](../../app/Console/SchedulerChain.php) holds "done today" and
"done this ISO week" flags. The two plan prerequisites mark themselves done today via
`->onSuccess()` when their exit code is 0; `plan:score-compliance` succeeds when at least one
athlete's pass completes (or there was nobody to score). `plan:regenerate`'s `->when()` gate opens
only once both are done today and closes again once a run has marked it done this week:

```php
Schedule::command('plan:regenerate')->mondays()->hourlyAt(26)->withoutOverlapping(45)
    ->when(static fn (): bool => SchedulerChain::prerequisitesMet(SchedulerChain::PLAN_REGENERATE)
        && ! SchedulerChain::isDoneThisWeek(SchedulerChain::PLAN_REGENERATE))
    ->onSuccess(static fn () => SchedulerChain::markDoneThisWeek(SchedulerChain::PLAN_REGENERATE));
```

Which prerequisites each gated command waits for lives in `SchedulerChain::PREREQUISITES`, so the
gates and the `/pulse` scheduler timeline (which renders each prerequisite as done/pending) read
the same map. A `->when()` gate keeps every command's own cron expression the single source of
truth for *when* it runs, and it is an extra filter Laravel checks via `Event::filtersPass()`, not a
replacement for the overlap/single-host locks.

**Why the entries retry.** A Monday entry that ran once at a single minute was lost for the whole
week when that minute was missed: a deploy or maintenance window across `00:00`-`00:26`, a killed
scheduler, or an eviction of the gate flags. Each entry now re-evaluates every hour (the
prerequisites and `streak:settle` every hour of every day, `plan:regenerate` every Monday hour) until it succeeds, and
the flag or cursor it leaves behind makes every later tick a no-op. The flags live on the `durable`
cache store ([config/cache.php](../../config/cache.php)), which is the AOF-backed `default` Redis
connection the scheduler mutexes and the heartbeat already use, not the allkeys-lru `cache`
instance, so an eviction or a restart of that instance no longer closes the gate. Tests run the
store on the `array` driver through `CACHE_DURABLE_DRIVER` in `phpunit.xml`. `streak:settle`
needs no flag: its durable per-athlete cursor already says who is behind, so a re-run queues only
those athletes and is a no-op once everyone is settled.

A gate that is still closed simply skips that tick; the skip is recorded in the run log (above) but
not alerted. Instead `schedule:monday-check` runs at 06:00 and, if `streak:settle` still has
an athlete behind or a plan entry has not succeeded, sends one maintainer alert for the week
through `MaintainerAlerter::mondayEntriesOverdue()`. The entries keep retrying after it. By then
six hourly `streak:settle` runs have had their chance, so an athlete it counts is genuinely stuck,
with one exception: an athlete marked dirty (or newly connected) after the 05:00 run still counts.

**Settlement and narration.** No recap reads the streak: `WeeklyRecapNarrator` reads only the
week's totals and plan context, and the monthly recap reads neither. The only narration that quotes
the settled streak is the weekly profile voice (`LifetimeStatsTool` → `ProfileVoiceNarrator`), so
the gate sits there, per athlete: an automatic profile-voice request (the weekly kickoff, the ingest
cascade, `ai:self-heal`, the settle-early replay) for an athlete who is not settled through the
latest closed week, or whose settled history is marked dirty, stays `Pending`
([AnalysisService::dispatchRow()](../../app/Services/AI/AnalysisService.php)), and `ai:self-heal`
narrates it on the first sweep after that athlete's settlement lands, which the hourly `streak:settle`
keeps to about two hours at most for a new or dirty athlete (one settle tick, then the next
self-heal sweep). One athlete behind holds back
nobody else. The athlete's own Reread does not wait. The rule is
[StreakSettlementService::unsettledUsers()](../../app/Services/Gamification/StreakSettlementService.php),
the same query `streak:settle` and `schedule:monday-check` use.

**The monthly recap** has no scheduled slot of its own. The ingest cascade stages each month's row
`Pending`, and the hourly `ai:self-heal` narrates it on its first sweep after the month closes;
quiet hours deliver the resulting push at 04:00.

## Cadence derivations (previously qualitative-only)

Three cadences carried a comment explaining the *shape* of the choice but no cited number. Each is
derived here from a number that already exists in the codebase. A fourth, `ai:trend-read 90d`'s
every-3-days cadence, was derived the same way but retired with the 90d range itself.

**`strava:sync-zones` — monthly.** HR zones are set from a Strava athlete's configured zones, which
change only when the athlete deliberately edits them in Strava — there is no measured "zones change
every N days" figure to derive from. The monthly sweep exists purely as a low-cost catch-all behind
the per-connect `SyncZonesJob` dispatch (the real trigger, on every OAuth connect/reconnect); a
tighter cadence would add scheduler load for a value that essentially never changes between
connects. Kept qualitative on purpose — there is no numeric input to derive it from.

**`queue:prune-failed --hours=168` — 7 days.** `Analysis::MAX_SELF_HEAL_ATTEMPTS` bounds a block to
a fixed number of hourly `ai:self-heal` attempts before it dead-letters (see
[docs/decisions/bounded-self-heal-and-dead-letter.md](../decisions/bounded-self-heal-and-dead-letter.md)),
which resolves in well under a day. `failed_jobs` rows are triage artifacts for that window, not the
source of truth (the `Analysis` row is) — 168h (7 days) is a full week of on-call visibility past
every self-heal cycle's resolution, long enough to catch a fault found on a Monday by the following
Monday, short enough that the table does not accumulate a month of superseded dupes.

**`strava:ingest` batch size 20** ([IngestCommand.php](../../app/Console/Commands/Strava/IngestCommand.php)).
This is the live-priority drain of pending activity stubs, at 2 Strava reads per activity (detail +
streams, per [ActivityPipeline](../../app/Services/Run/Ingest/ActivityPipeline.php)) — a batch of 20
spends at most 40 reads per tick. Running every 5 minutes, three ticks fall inside one 15-minute
window, so a fully-loaded drain spends up to 120 of that window's 200 reads
([StravaClient::RATE_LIMIT_15MIN_MAX](../../app/Services/Strava/StravaClient.php)). The hourly
`strava:sync` fallback poll is also live and lands at :07, in the window that opens on the hour: one
activity-list page per connected athlete (more only for an athlete with over 200 new activities
since the last poll), so at most 10 reads at the current 10-athlete Strava tier. The worst live
window is therefore 120 + 10 = 130 reads (65%), before webhook-driven ingest, which is live too and
uncapped by any cadence.

`strava:hydrate-backlog`'s background drain shares the same bucket on its every-15-minutes cadence,
but a background read is refused once the window's counted usage reaches 150, because the
[25% live reserve](../../app/Services/Strava/StravaClient.php) holds the last 50 reads for live
reads. So live reads always have at least 50 reads in a window. They get the full 130 only when
background reads have not already pushed the window past 70. If the drain fills the window to 150
first, the rest of that window's ingest batch is refused and deferred to the next window. Live
reads therefore cannot exhaust the 200 on their own at this size, and the reserve keeps ingest moving
through a busy window, but it does not guarantee a full batch in every window. Counted usage is the
higher of the local count and the usage Strava last reported for that window (see [[strava-client]]),
so reads made outside this app shrink the same headroom.

## See also

- [[deployment]] — the single `scheduler` service and its healthcheck
- [[background-hydration-drain]], [[backfill-borrows-the-live-reserve]] — the Strava read buckets `strava:ingest`'s batch size is sized against
- [[bounded-self-heal-and-dead-letter]] — the self-heal attempt bound `queue:prune-failed`'s retention is sized against
- [[llm-triggers]] — the full LLM-trigger surface, including every AI-narration cadence in this file
