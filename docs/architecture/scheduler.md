---
title: Scheduler hygiene — overlap safety, single-host, ordering, cadence
description: Every Schedule::command entry is overlap-safe and single-host by an unstated one-container invariant; the Monday window's hard dependencies are now chained rather than only spaced; the numeric derivation behind four previously-qualitative cadences; and measured local runtimes next to each lock TTL
tags: [architecture, scheduler]
status: living
reviewed: 2026-09-10
code_refs:
  - routes/console.php
  - app/Console/SchedulerChain.php
  - compose.prod.yaml
  - config/strava.php
  - config/cache.php
---

# Scheduler hygiene

[routes/console.php](../../routes/console.php) registers every scheduled command. This note covers
four things: why every event carries both `withoutOverlapping()` and `onOneServer()`, how the two
hard dependencies in the Monday window are chained (not just spaced), the numeric basis for four
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
and taking a lock on the evictable cache store every minute buys nothing (see its comment in
`routes/console.php`). It still takes `onOneServer()`, since a second scheduler container should
not double-write a heartbeat any more than it should double-run anything else.

`onOneServer()` and `withoutOverlapping()` both key their lock through the app's default cache
store ([config/cache.php](../../config/cache.php) — `redis` in prod via `CACHE_STORE`, `array` in
tests via `phpunit.xml`, `database` from `.env.example` locally). `onOneServer()` additionally
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
| `schedule:heartbeat` | every minute | — (deliberate) | yes | one idempotent `SETEX`; a lock would cost more than the write itself | ~1.1s, but exits on `redis unreachable` — `.env.example` ships `REDIS_HOST=127.0.0.1`/`CACHE_STORE=database` for local dev, so the real Redis `SETEX` path cannot be exercised in this worktree at all |
| `ai:daily-briefing` | daily 00:01 | 30 | yes | per-user dispatch loop over active (7d) users; 30 min is generous headroom before the next day's run | ~1.9s — dispatched for 0 active users (the seeded demo user is excluded from AI kickoff billing) |
| `demo:daily-refresh` | daily 00:13 | 10 | yes | single demo user, one synthetic run + rule-based fill | ~2.2s — the one command that actually touches the seeded user (rule-based refresh, no LLM) |
| `plan:close-finished-races` | daily 00:04 | 10 | yes | one bulk `UPDATE ... WHERE race_date < today` | ~1.5s — closed 0 races |
| `plan:score-compliance` | daily 00:09 | 20 | yes | bounded by `--limit=500` users, one scoring pass each | ~1.3s — scored 0 planned rows |
| `ai:weekly-recap` | Mon 00:16 | 30 | yes | per-user dispatch loop, same shape as `ai:daily-briefing` | ~1.5s — 0 snapshots (demo excluded) |
| `ai:weekly-profile` | Mon 00:21 | 20 | yes | per-active-user dispatch loop, lighter than the recap (one row type) | ~1.4s — 0 active users (demo excluded) |
| `plan:regenerate` | Mon 00:26 | 45 | yes | heaviest entry: `Periodizer::regenerate()` + `PlanNarrationRequester` per user | ~1.4s — regenerated for the 1 seeded user; `PlanNarrationRequester` dispatched no LLM calls (Azure unconfigured) |
| `strava:sync-zones` | monthly 00:10 | 55 (unchanged) | yes | already guarded pre-DF-1 | ~1.7s — no eligible connection (the seeded demo `StravaConnection` carries a synthetic token, not a real Strava one, and is excluded) |
| `ai:monthly-recap` | monthly 05:45 | 30 | yes | per-user dispatch loop, monthly cadence gives ample headroom | ~1.4s — 0 months dispatched (demo excluded) |
| `ai:trend-read {30d,90d,12mo}` | daily/every-3-days/weekly 06:00 | 20 | yes | one narrator pass across users per range | ~1.2-1.4s each — 0 active users (demo excluded) |
| `ai:self-heal` | hourly | 55 (unchanged) | yes | already guarded pre-DF-1 | ~1.3s — skipped, generation paused (Azure unset) |
| `ai:catch-up` | hourly | 55 (unchanged) | yes | already guarded pre-DF-1 | ~1.5s — created 0 missing kickoff rows |
| `queue:prune-failed` | daily 02:20 | 15 | yes | one `DELETE` on `failed_jobs` | ~1.6s — 0 entries deleted |
| `analytics:prune` | daily 02:25 | 15 | yes | four `DELETE`s — three on the `analytics` connection, one on `analysis_versions` | ~1.7s — 0 rows pruned |
| `strava:sync` / `strava:ingest` / `strava:hydrate-backlog` | see `routes/console.php` | 55/10/14 (unchanged) | yes | already guarded pre-DF-1 | ~1.3-1.4s each — no real Strava connection to poll/drain against locally (needs live Strava credentials); cannot be meaningfully measured in this worktree |
| `geo:backfill-locations` / `weather:correct-forecast` / `weather:backfill` | see `routes/console.php` | 55/55/55 (unchanged) | yes | already guarded pre-DF-1 | ~1.3s each — 0 rows to backfill; `weather:*` additionally need a live Open-Meteo call to exercise the fetch path |
| `trend:snapshot-daily` | daily 03:45 | 55 (unchanged) | yes | already guarded pre-DF-1 | ~1.6s — wrote 1 row, for the seeded user |
| `race:remind` | daily 18:00 | 15 | yes | one race-goal sweep, same shape and cost as `streak:remind` | not measured — added after this pass; the sweep is one indexed `race_date` query plus one notify per athlete racing tomorrow |
| `briefing:morning-push` | every 15 min | 14 | yes | one median-start-time sweep, sized like the other quarter-hourly drain; sends only, generates nothing | not measured — added after this pass; the median is cached per athlete per day (`UsualRunTime`), so only the first tick to see a given athlete that day pays the indexed read, every later tick that day is a cache hit |
| `streak:remind` | Sat 18:00 | 15 | yes | one push-eligibility sweep | ~2.0s — dispatched to 0 users |
| `streak:settle` | Mon 00:00 | 20 | yes | per-user token settle over users with a `WeeklySnapshot` | ~1.5s — minted 0, spent 0 |

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

## The Monday window: ordering, not just spacing

The old Monday window packed 8 entries into `00:00`-`00:07` with only comment-documented "must run
after" relationships and no enforced ordering — each `withoutOverlapping()` lock is scoped to its
own command, so nothing stopped two *different* commands from racing each other. Re-staggered to
`00:00`-`00:26`, with the gap sized to the dependency it protects rather than to a uniform minute
step:

| command | time | must run after | why |
|---|---|---|---|
| `streak:settle` | 00:00 | — | independent trigger; settles the week that just closed before anything narrates it |
| `ai:daily-briefing` | 00:01 | — | daily cadence, unrelated to the Monday-only chain below |
| `plan:close-finished-races` | 00:04 | — | independent trigger; must itself finish well before `plan:regenerate` (00:26) |
| `plan:score-compliance` | 00:09 | — | independent trigger; must itself finish well before `plan:regenerate` (00:26) |
| `demo:daily-refresh` | 00:13 | — | independent, single demo user, zero LLM cost |
| `ai:weekly-recap` | 00:16 | `streak:settle` (00:00) | reads `consecutiveWeekStreak()` — narrating before the settle would freeze a streak `streak:settle` is about to restore or forgive |
| `ai:weekly-profile` | 00:21 | `ai:weekly-recap` (00:16) | refreshes "just after the recap" by convention, though it reads no recap output directly — no hard code dependency, kept for narrative consistency |
| `plan:regenerate` | 00:26 | `plan:close-finished-races` (00:04), `plan:score-compliance` (00:09) | regenerates today-forward off the newly retired races (`CloseFinishedRacesCommand`'s own docblock: an unretired race made `PhaseSchedule::forRace()` throw) and reads last week's average compliance score |

The two hard dependencies — `streak:settle` → `ai:weekly-recap`, and
`plan:close-finished-races` + `plan:score-compliance` → `plan:regenerate` — are now **chained**, not
just spaced: [SchedulerChain](../../app/Console/SchedulerChain.php) is a tiny "prerequisite done
today" cache flag. Each prerequisite marks itself done via `->onSuccess()` when its exit code is 0;
each dependent's `->when()` gate refuses to run until every prerequisite it needs has marked itself
done for the current date. Concretely, in `routes/console.php`:

```php
Schedule::command('streak:settle')->weeklyOn(1, '00:00')->withoutOverlapping(20)->onOneServer()
    ->onSuccess(static fn () => SchedulerChain::markDoneToday(SchedulerChain::STREAK_SETTLE));

Schedule::command('ai:weekly-recap')->weeklyOn(1, '00:16')->withoutOverlapping(30)->onOneServer()
    ->when(static fn (): bool => SchedulerChain::isDoneToday(SchedulerChain::STREAK_SETTLE));
```

and the same shape for `plan:regenerate`'s `->when()`, which checks both
`plan:close-finished-races` and `plan:score-compliance`. This was chosen over an
`Event::then()`/`Artisan::call()` chain that runs the dependent immediately after its prerequisite:
a `->when()` gate keeps every command's own cron expression the single source of truth for *when*
it runs (`schedule:list` still shows `ai:weekly-recap` at its own `16 0 * * 1`, not folded into
`streak:settle`'s entry), while still making the dependency load-bearing rather than assumed. The
existing `withoutOverlapping()`/`onOneServer()` guards on every event are untouched — `->when()` is
an additional filter Laravel checks via `Event::filtersPass()` before a due event runs, not a
replacement for the overlap/single-host locks.

The staggered times (00:00 → 00:16 → 00:21 → 00:26) stay as a **fallback**, not the enforcement: in
practice the flag is almost always already set by the time the dependent's own cron tick fires,
since every command in this window finishes in low single-digit seconds even before accounting for
its own generous `withoutOverlapping` TTL (see the measured-locally column in the table above) — so
spacing alone would still work at today's scale, but the `->when()` gate is what makes it correct
rather than merely likely, and is what protects the ordering once a slow run, a retry, or a future
higher user count makes "usually finishes first" no longer safe to assume. A prerequisite that
fails (non-zero exit) never marks itself done, so a failed `streak:settle` correctly holds back
`ai:weekly-recap` for that Monday rather than letting it narrate a streak the settle never applied
— it picks back up automatically the following Monday once the prerequisite succeeds again.

## Cadence derivations (previously qualitative-only)

Four cadences carried a comment explaining the *shape* of the choice but no cited number. Each is
derived here from a number that already exists in the codebase.

**`strava:sync-zones` — monthly.** HR zones are set from a Strava athlete's configured zones, which
change only when the athlete deliberately edits them in Strava — there is no measured "zones change
every N days" figure to derive from. The monthly sweep exists purely as a low-cost catch-all behind
the per-connect `SyncZonesJob` dispatch (the real trigger, on every OAuth connect/reconnect); a
tighter cadence would add scheduler load for a value that essentially never changes between
connects. Kept qualitative on purpose — there is no numeric input to derive it from.

**`ai:trend-read 90d` — every 3 days (`cron('0 6 */3 * *')`).** The 90-day range's own view window
is 90 days; three narrations across that window (day 1, ~day 31-33, ~day 61-63, modulo the
month-boundary reset the comment already documents) sample it at roughly a third of its own span,
which is frequent enough that the window's oldest and newest thirds are never more than ~30 days
stale relative to each other while costing a third of the daily `30d` cadence. The 3-day figure is
one-third of `TREND_READ_RANGES`'s own **medium** tier relative to its `30d`/`12mo` neighbours
(daily and weekly respectively) — see the `ai:trend-read` registrations in
[routes/console.php](../../routes/console.php).

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
