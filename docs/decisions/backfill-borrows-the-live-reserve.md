---
title: Backfill borrows the live reserve while live traffic is low
description: The daily read bucket stops reserving a fixed quarter for live ingest and reserves a flat floor instead, so background hydration spends whatever live ingest leaves unspent that day; the 15-minute burst guard is unchanged.
tags: [decision, strava]
status: accepted
reviewed: 2026-09-09
code_refs:
  - app/Services/Strava/StravaClient.php
  - config/strava.php
  - app/Console/Commands/Strava/HydrateBacklogCommand.php
---

# Backfill borrows the live reserve while live traffic is low

**Status:** Accepted (2026-09-09)

## Context

[[live-ingest-read-reserve]] gave the daily read bucket a percentage reserve: a `Background` read is refused at 75% of the pool, so hydration could never spend more than 1,500 of the 2,000 daily reads. [[background-hydration-drain]] then sized its every-15-minute tick from exactly that ceiling.

The reserve was sized as a percentage because nobody had measured what live ingest actually costs. It has now been measured, and it is small. Live reads per athlete per day:

- **24** — the hourly `strava:sync` fallback poll, one page of `/athlete/activities` per connected athlete per tick ([ActivityFetcher](app/Services/Strava/ActivityFetcher.php#L11)).
- **~1** — webhook-driven ingest of a finished run, two reads apiece ([detail](app/Services/Run/Ingest/ActivityPipeline.php#L78), [streams](app/Services/Run/Ingest/ActivityPipeline.php#L257)), at 4 runs/week ≈ 0.57 runs/day.

That is **~25 reads per athlete per day**, matching the ~251 reads/day observed across today's 10 athletes — **12.6% of the daily pool**, with the 15-minute bucket barely touched. A 500-read reserve is twice what the whole cohort's live traffic costs, and it is idle for the ~87% of the pool live ingest never asks for.

The cost of that idleness lands on exactly the people who can least afford it. A cohort of new signups all backfilling at once shares one 1,500-read ceiling, which is what makes a new athlete's Trends look broken for days while their history converges — the first impression, paid for with headroom nothing else was using.

## Decision

**The daily bucket reserves a flat floor, not a percentage.** A `Background` read is refused once the daily bucket reaches `2,000 - strava.live_read_floor` = **1,600** attempts ([backgroundCeilings()](app/Services/Strava/StravaClient.php#L345)), where the floor is [config/strava.php](config/strava.php)'s `live_read_floor`, default 400, overridable with `STRAVA_LIVE_READ_FLOOR`.

Because the ceiling sits on a **shared counter**, this is already the dynamic behaviour: background reads available today = `1,600 - live reads already spent today`. Live traffic near zero and background gets the whole 1,600; a busy live day shrinks background to the leftover, read for read, with no second counter and no per-user key. `Live` still spends the whole 2,000, so background yields first when the pool runs low, exactly as before.

**The floor is 400 because live costs ~25 reads/athlete/day**: 10 athletes × 25 = 250, plus ~60% margin. It covers roughly **16 athletes** at today's rate before the floor itself is the thing that binds — which is why it is config with an env override rather than a constant, and why the number must be revisited as the athlete count grows.

**The 15-minute bucket keeps its 25% reserve unchanged** (150 / 200). A burst is what gets an app throttled by Strava, and the 15-minute window is the burst guard; nothing measured suggests loosening it. At 150 reads per window it also affords 14,400 background reads a day, so the daily bucket is the binding constraint either way.

**The drain is untouched.** [HydrateBacklogCommand](app/Console/Commands/Strava/HydrateBacklogCommand.php#L75) still sizes its tick from `min(backgroundHeadroom())` and still splits it evenly across users with a backlog in id order, so one deep archive cannot starve another's.

### What this is worth, honestly

Background reads per day, at the measured 251 reads/day of live traffic:

| | ceiling | minus live | background reads/day |
|---|---|---|---|
| before | 1,500 | 251 | 1,249 |
| after | 1,600 | 251 | 1,349 |

For the motivating case — 10 simultaneous onboarders, ~10,000 reads of backfill — that is **8.0 days → 7.4 days**. Real, but modest. **The binding constraint is the 2,000/day pool itself, not the reserve**: even a zero reserve would only reach 5.6 days. The reserve was worth removing because it was provably idle, not because it was the bottleneck; a cohort that needs to converge faster than a week needs a larger Strava allocation or fewer reads per run, not a smaller floor.

### Why not a second counter for live reads

A separate live-reads-today counter would let the ceiling be expressed literally as `pool - floor - live_used`. Against one shared counter that expression reduces to the constant `pool - floor`, so the second counter buys nothing but a second key to keep correct — and this pool has already shipped a per-user-keying bug once (PR #206). One counter, one key shape, one threshold.

### Why not drop the reserve entirely

Then a deep backfill could take the last read of the day and a freshly-finished run would sit unsynced until midnight. The floor is what keeps the product's core promise from being spendable by a batch job.

## Consequences

- **Enables:** a new athlete's history converges ~8% faster, and the whole daily pool above the floor is reachable by backfill on a quiet day instead of being reserved for traffic that never arrives.
- **Costs:** the reserve no longer scales with the athlete count on its own. At ~16 athletes the 400-read floor is fully spoken for by live traffic; past that it must be raised deliberately, or live ingest starts competing with backfill for the same reads.
- **Unchanged:** the 15-minute guard, the global key shape, the drain's ordering and even split, `rateLimitRemaining()` still reporting the raw pool for the sync log and Pulse card, and `Live` reads spending the whole pool.

## See also

- [[live-ingest-read-reserve]] — the percentage reserve this replaces on the daily bucket, and keeps on the 15-minute one
- [[background-hydration-drain]] — the drain that spends this headroom, unchanged
- [[strava-circuit-breaker-rate-limit]] — the per-client global keying underneath both
- [[strava-client]] — the operational mechanics of the buckets
