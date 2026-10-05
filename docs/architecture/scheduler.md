---
title: Scheduler hygiene — overlap safety, single-host, ordering, cadence
description: Every Schedule::command entry is overlap-safe and single-host by an unstated one-container invariant; the Monday window's hard dependencies are chained and retry hourly until they succeed; the numeric derivation behind four previously-qualitative cadences; and measured local runtimes next to each lock TTL
tags: [architecture, scheduler]
status: living
reviewed: 2026-10-05
code_refs:
  - routes/console.php
  - app/Console/Commands/Notifications/RecoverStaleNotificationDeliveriesCommand.php
  - app/Console/Commands/Notifications/ReleaseHeldNotificationsCommand.php
  - app/Services/Notifications/NotificationDeliveryClaim.php
  - app/Console/SchedulerChain.php
  - app/Console/Commands/MondayCheckCommand.php
  - app/Services/Gamification/StreakSettlementService.php
  - compose.prod.yaml
  - config/strava.php
  - config/cache.php
---

# Scheduler hygiene

[routes/console.php](../../routes/console.php) registers every scheduled command. This note covers
four things: why every event carries both `withoutOverlapping()` and `onOneServer()`, how the
Monday window's entries are chained and caught up after a miss, the numeric basis for four
cadences that were previously justified only qualitatively, and a measured-locally runtime next to
each lock TTL.

## Overlap safety and single-host

[compose.prod.yaml](../../compose.prod.yaml) defines exactly **one** `scheduler` service. That is
the only reason two schedulers have never double-run a command — an unstated invariant, not an
enforced one. `onOneServer()` makes that invariant free to hold today and load-bearing the moment
the container ever scales past one; `withoutOverlapping()` guards the orthogonal case of the same
container's *next* tick starting before the current run has finished. Every event in
`routes/console.php` now carries both, with one deliberate exception:

`schedule:heartbeat` skips `withoutOverlapping()` on purpose — the write is one idempotent `SETEX`,
so a mutex taken every minute would guard nothing (see its comment in `routes/console.php`). It still takes `onOneServer()`, since a second scheduler container should
not double-write a heartbeat any more than it should double-run anything else.

`onOneServer()` and `withoutOverlapping()` both key their lock through the app's default cache
store ([config/cache.php](../../config/cache.php) — `redis` in prod via `CACHE_STORE`, `array` in
tests via `phpunit.xml`, `database` from `.env.example` locally). The `redis` store takes its locks
on its `lock_connection`, the durable `default` connection, so an eviction on the allkeys-lru
`cache` instance never drops a held mutex. `onOneServer()` additionally
needs a store every scheduler container can reach, which `redis` is in prod; `array` works for
tests because a Pest run is one process regardless of parallel workers.

### Lock TTL and onOneServer, per command

The "measured locally" column is one `time ./vendor/bin/sail artisan <command>` run per command
against this worktree's `demo:seed` data (**1 user, 127 activities**), Azure unconfigured so every
AI command takes its dispatch-only/paused path rather than calling an LLM. Every command finished
in ~1-2 seconds, which is dominated by `artisan`'s own PHP/framework bootstrap, not by the
command's own query — at this user count the per-user loops these TTLs guard against are
essentially invisible. See "Reading the local measurements" below for why the TTLs stay as-is
despite that.

| command | cadence | withoutOverlapping | onOneServer | why this TTL | measured locally on seeded data (1 user, 127 activities) |
|---|---|---|---|---|---|
| `notifications:recover-deliveries` | every 5 minutes | 10 | yes | one indexed scan of stale pending claims; Telegram claims become terminal, while web-push retries stay discoverable until a worker claims a new version | not measured — added with fenced notification recovery |
| `notifications:release-held` | every 5 minutes | 10 | yes | a no-op inside quiet hours (22:00-04:00); outside them, queues each held row in id order, one small transaction per row. Every five minutes rather than once at 04:00, so a deploy across 04:00 delays the release by one tick ([[quiet-hours-hold-every-notification]]) | not measured — added with quiet hours |
| `schedule:heartbeat` | every minute | — (deliberate) | yes | one idempotent `SETEX`; a lock would cost more than the write itself | ~1.1s, but exits on `redis unreachable` — `.env.example` ships `REDIS_HOST=127.0.0.1`/`CACHE_STORE=database` for local dev, so the real Redis `SETEX` path cannot be exercised in this worktree at all |
| `ai:daily-briefing` | daily 00:01 | 30 | yes | per-user dispatch loop over active (7d) users; 30 min is generous headroom before the next day's run | ~1.9s — dispatched for 0 active users (the seeded demo user is excluded from AI kickoff billing) |
| `demo:daily-refresh` | daily 00:13 | 10 | yes | single demo user, one synthetic run + rule-based fill | ~2.2s — the one command that actually touches the seeded user (rule-based refresh, no LLM) |
| `plan:close-finished-races` | daily 00:04, retried hourly at :04 until it succeeds that day | 10 | yes | one bulk `UPDATE ... WHERE race_date < today` | ~1.5s — closed 0 races |
| `plan:score-compliance` | daily 00:09, retried hourly at :09 until it succeeds that day | 20 | yes | bounded by `--limit=500` users, one scoring pass each | ~1.3s — scored 0 planned rows |
| `ai:weekly-recap` | Mon 00:16 | 30 | yes | per-user dispatch loop, same shape as `ai:daily-briefing` | ~1.5s — 0 snapshots (demo excluded) |
| `ai:weekly-profile` | Mon 00:21 | 20 | yes | per-active-user dispatch loop, lighter than the recap (one row type) | ~1.4s — 0 active users (demo excluded) |
| `plan:regenerate` | Mon 00:26, retried every Monday hour at :26 until one run succeeds that week | 45 | yes | heaviest entry: `Periodizer::regenerate()` + `PlanNarrationRequester` per user | ~1.4s — regenerated for the 1 seeded user; `PlanNarrationRequester` dispatched no LLM calls (Azure unconfigured) |
| `strava:sync-zones` | monthly 00:10 | 55 (unchanged) | yes | already guarded pre-DF-1 | ~1.7s — no eligible connection (the seeded demo `StravaConnection` carries a synthetic token, not a real Strava one, and is excluded) |
| `ai:trend-read 7d` | daily 06:00 | 20 | yes | one narrator pass across active users | ~1.2-1.4s — 0 active users (demo excluded) |
| `ai:self-heal` | hourly | 55 (unchanged) | yes | already guarded pre-DF-1 | ~1.3s — skipped, generation paused (Azure unset) |
| `ai:catch-up` | hourly | 55 (unchanged) | yes | already guarded pre-DF-1 | ~1.5s — created 0 missing kickoff rows |
| `queue:prune-failed` | daily 02:20 | 15 | yes | one `DELETE` on `failed_jobs` | ~1.6s — 0 entries deleted |
| `analytics:prune` | daily 02:25 | 15 | yes | five `DELETE`s — four on the `analytics` connection, one on `analysis_versions` | ~1.7s — 0 rows pruned |
| `model:prune TelegramUpdateReceipt` | daily 02:30 | 15 | yes | delete Telegram update receipts older than 7 days | not measured — added with durable Telegram update dedupe |
| `model:prune TelegramLinkTokenUse` | daily 02:31 | 15 | yes | delete spent link-token claims after token expiry | not measured — added with durable Telegram link claims |
| `notifications:prune-push-subscriptions` | daily 02:35 | 15 | yes | one `SELECT DISTINCT` and one `DELETE` on `push_subscriptions` for rows unseen for 60 days | not measured — added with unseen push-subscription pruning |
| `strava:sync` / `strava:ingest` / `strava:hydrate-backlog` | see `routes/console.php` | 55/10/14 (unchanged) | yes | already guarded pre-DF-1 | ~1.3-1.4s each — no real Strava connection to poll/drain against locally (needs live Strava credentials); cannot be meaningfully measured in this worktree |
| `geo:backfill-locations` / `weather:correct-forecast` / `weather:backfill` | see `routes/console.php` | 55/55/55 (unchanged) | yes | already guarded pre-DF-1 | ~1.3s each — 0 rows to backfill; `weather:*` additionally need a live Open-Meteo call to exercise the fetch path |
| `trend:snapshot-daily` | daily 03:45 | 55 (unchanged) | yes | queues durable closed-date recovery in 365-day chunks; `--days=N` remains the focused mode | scheduled recovery advances each user's cursor through yesterday; ingest repairs backdated ranges |
| `race:remind` | daily 18:00 | 15 | yes | one race-goal sweep, same shape and cost as `streak:remind` | not measured — added after this pass; the sweep is one indexed `race_date` query plus one notify per athlete racing tomorrow |
| `race:ask-outcome` | daily 09:00 | 15 | yes | one indexed sweep of races dated yesterday with a pending outcome, one notify each | not measured — added with CR-06; same shape and cost as `race:remind` |
| `plan:settle-time-trials` | daily 09:05 | 15 | yes | one indexed sweep of the last week's unsettled time-trial rows for non-demo athletes, at most one evidence write or one notify each; a settled row is never selected again | not measured — added with the time trials (#1808) |
| `fitness:notify-improvement` | daily 10:00 | 30 | yes | one estimate per non-demo athlete against their last noted VDOT, one notify for each improvement of at least 0.5 a week apart; the first run only records baselines | not measured — added with the supported-race-time model; the estimate reads each athlete's runs once |
| `briefing:morning-push` | every 15 min | 14 | yes | one median-start-time sweep, sized like the other quarter-hourly drain; sends only, generates nothing | not measured — added after this pass; the median is cached per athlete per day (`UsualRunTime`), so only the first tick to see a given athlete that day pays the indexed read, every later tick that day is a cache hit |
| `streak:remind` | Sat 18:00 | 15 | yes | one push-eligibility sweep | ~2.0s — dispatched to 0 users |
| `streak:settle` | hourly | 20 | yes | queues chronological per-user settlement for the athletes still behind or marked dirty; one query when nobody is | queues one settlement job per athlete behind |
| `schedule:monday-check` | Mon 06:00 | 10 | yes | one indexed count plus the chain flags, at most one alert per week | not measured — added with the Monday catch-up |
| `RetryOrphanedStravaGrantReleasesJob` (queued job) | daily 02:40 | 30 | yes | retries the Strava release of grants whose local connection is gone or revoked, one call per orphaned grant | not measured — the scheduler only queues it |

Values marked "unchanged" already had `withoutOverlapping()` before this pass and keep their
existing TTL; only `onOneServer()` was added to those.

**Reading the local measurements.** Every TTL stays as originally sized (a handful of DB writes vs.
a per-user loop, with headroom against the command's own cadence) rather than being tightened to
match these sub-2-second runs: the measurements above are essentially `artisan` bootstrap overhead
against a single-user, single-connection dataset, not a load test. The commands whose TTL exists
specifically to guard a *per-user* loop (`ai:daily-briefing`, `ai:weekly-recap`,
`plan:score-compliance`, `plan:regenerate`, the `ai:trend-read` family) scale with athlete count —
at 1 user their real cost is invisible, and the TTL's headroom is what protects against that loop
taking materially longer once the athlete base grows. Nothing measured here contradicts an existing
TTL; it simply confirms none of them are already too tight at today's scale.

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
Schedule::command('plan:regenerate')->mondays()->hourlyAt(26)->withoutOverlapping(45)->onOneServer()
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

A gate that is still closed simply skips that tick; individual skips are not alerted (recording them
belongs to #1786). Instead `schedule:monday-check` runs at 06:00 and, if `streak:settle` still has
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
every-3-days cadence, was derived the same way but retired with the 90d range itself (#967).

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

**`strava:ingest` batch size 20** ([IngestCommand.php#L15](../../app/Console/Commands/Strava/IngestCommand.php#L15)).
This is the live-priority drain of pending activity stubs, at 2 Strava reads per activity (detail +
streams, per [ActivityPipeline](../../app/Services/Run/Ingest/ActivityPipeline.php)) — a batch of 20
spends at most 40 reads per tick. Running every 5 minutes, three ticks fall inside one 15-minute
window, so a fully-loaded run spends up to 120 reads against that window's 200-read cap
([StravaClient::RATE_LIMIT_15MIN_MAX](../../app/Services/Strava/StravaClient.php)) — 60%, leaving
the remaining 40% (80 reads) for `strava:hydrate-backlog`'s background drain, which shares the same
15-minute bucket on its own every-15-minutes cadence. Sized so the live-priority drain cannot alone
exhaust the burst guard the 15-minute bucket exists to enforce.

## See also

- [[deployment]] — the single `scheduler` service and its healthcheck
- [[background-hydration-drain]], [[backfill-borrows-the-live-reserve]] — the Strava read buckets `strava:ingest`'s batch size is sized against
- [[bounded-self-heal-and-dead-letter]] — the self-heal attempt bound `queue:prune-failed`'s retention is sized against
- [[llm-triggers]] — the full LLM-trigger surface, including every AI-narration cadence in this file
