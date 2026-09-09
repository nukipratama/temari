---
title: Scheduler hygiene — overlap safety, single-host, ordering, cadence
description: Every Schedule::command entry is overlap-safe and single-host by an unstated one-container invariant; the Monday window's dependency ordering; and the numeric derivation behind four previously-qualitative cadences
tags: [architecture, scheduler]
status: living
reviewed: 2026-09-10
code_refs:
  - routes/console.php
  - compose.prod.yaml
  - config/cache.php
---

# Scheduler hygiene

[routes/console.php](../../routes/console.php) registers every scheduled command. This note covers
why every event carries both `withoutOverlapping()` and `onOneServer()`.

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

| command | cadence | withoutOverlapping | onOneServer | why this TTL |
|---|---|---|---|---|
| `schedule:heartbeat` | every minute | — (deliberate) | yes | one idempotent `SETEX`; a lock would cost more than the write itself |
| `ai:daily-briefing` | daily 00:01 | 30 | yes | per-user dispatch loop over active (7d) users; 30 min is generous headroom before the next day's run |
| `demo:daily-refresh` | daily 00:05 | 10 | yes | single demo user, one synthetic run + rule-based fill |
| `plan:close-finished-races` | daily 00:02 | 10 | yes | one bulk `UPDATE ... WHERE race_date < today` |
| `plan:score-compliance` | daily 00:03 | 20 | yes | bounded by `--limit=500` users, one scoring pass each |
| `ai:weekly-recap` | Mon 00:01 | 30 | yes | per-user dispatch loop, same shape as `ai:daily-briefing` |
| `ai:weekly-profile` | Mon 00:05 | 20 | yes | per-active-user dispatch loop, lighter than the recap (one row type) |
| `plan:regenerate` | Mon 00:07 | 45 | yes | heaviest entry: `Periodizer::regenerate()` + `PlanNarrationRequester` per user |
| `strava:sync-zones` | monthly 00:10 | 55 (unchanged) | yes | already guarded pre-DF-1 |
| `ai:monthly-recap` | monthly 05:45 | 30 | yes | per-user dispatch loop, monthly cadence gives ample headroom |
| `ai:trend-read {30d,90d,12mo}` | daily/every-3-days/weekly 06:00 | 20 | yes | one narrator pass across users per range |
| `ai:self-heal` / `ai:catch-up` | hourly | 55 (unchanged) | yes | already guarded pre-DF-1 |
| `queue:prune-failed` | daily 02:20 | 15 | yes | one `DELETE` on `failed_jobs` |
| `analytics:prune` | daily 02:25 | 15 | yes | a couple of `DELETE`s on the `analytics` connection |
| `strava:sync` / `strava:ingest` / `strava:hydrate-backlog` / `geo:backfill-locations` / `weather:correct-forecast` / `weather:backfill` / `trend:snapshot-daily` | see `routes/console.php` | 55/10/14/55/55/55/55 (unchanged) | yes | already guarded pre-DF-1 |
| `streak:remind` | Sat 18:00 | 15 | yes | one push-eligibility sweep |
| `streak:settle` | Mon 00:00 | 20 | yes | per-user token settle over users with a `WeeklySnapshot` |

Values marked "unchanged" already had `withoutOverlapping()` before this pass and keep their
existing TTL; only `onOneServer()` was added to those. Every other TTL is new, sized from the
command's own query shape (a handful of DB writes vs. a per-user loop) with headroom against its
own cadence, not measured wall-clock — there is no production load yet to measure against.

## See also

- [[deployment]] — the single `scheduler` service and its healthcheck
- [[llm-triggers]] — the full LLM-trigger surface, including every AI-narration cadence in this file
