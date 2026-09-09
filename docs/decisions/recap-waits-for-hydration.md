---
title: A weekly recap waits for its week to finish hydrating
description: The recap kickoff and the self-heal sweep hold a week back while the ingest pipeline still owes it a detail fetch, bounded by a wall-clock grace window rather than a deferral counter.
tags: [decision, ai, run, strava]
status: accepted
reviewed: 2026-09-09
code_refs:
  - app/Services/AI/RecapHydrationReadiness.php
  - app/Actions/AI/KickoffWeeklyRecaps.php
  - app/Services/AI/SelfHealer.php
  - app/Models/Activity.php
  - config/ai.php
---

# A weekly recap waits for its week to finish hydrating

**Status:** Accepted (2026-09-09)

## Context

[[background-hydration-drain]] closed with an unenforced rule under *Consequences*: *"Ordering matters, and is not enforced in code. A recap generated before its weeks are hydrated narrates null load and, being `invalidate: false`, keeps that thin story. Drain first, kick off recaps second."*

Nothing enforced it. `strava:hydrate-backlog` runs every fifteen minutes and `ai:weekly-recap` fires Monday 00:01 ([routes/console.php](routes/console.php)); neither knows about the other. Two shapes lose the race for real:

- **a new athlete's first Monday** — the connect backfill lands 179 summary rows, the drain is still working through them at 00:01, and the week that just closed is narrated against partial splits and a null `weekly_trimp`;
- **after an outage** — the scheduler container comes back with a backlog behind it and the same thing happens to whatever week the sweep reaches first.

The damage is permanent rather than transient: recaps are requested `invalidate: false` ([KickoffWeeklyRecaps](app/Actions/AI/KickoffWeeklyRecaps.php)), so nothing ever re-narrates a week that got a thin story. [WeeklyRecapNarrator](app/Services/AI/Narrators/WeeklyRecapNarrator.php) reads `weekly_trimp`, and the drain's own measurement was 303 of 308 weekly snapshots carrying a null one.

## Decision

**A week is not narrated while the ingest pipeline still owes one of its runs a hydration, and the wait is bounded by wall clock.** [RecapHydrationReadiness](app/Services/AI/RecapHydrationReadiness.php) answers "may this week be narrated now?" for a set of snapshots in one pass, and both entry points into weekly narration consult it.

### What "un-hydrated" means

It is read off the pipeline's own states, not a new flag: [`Activity::awaitingHydration()`](app/Models/Activity.php) is a stub or a summary-only row whose `detail_fail_count` is still under `MAX_DETAIL_FETCH_ATTEMPTS` — precisely the union of what `strava:ingest` and `strava:hydrate-backlog` between them are still going to turn into `IngestState::Detailed` rows. A run the pipeline has given up on (a permanent 4xx, which deliberately leaves `ingest_state` at `summary` — see [[background-hydration-drain]]) drops out of the set by its attempt count, so a deleted or unshared run cannot hold a week hostage forever.

A run is placed in a week by `activity_details.start_date_local`, the same Monday–Sunday boundary [WeeklyAggregator](app/Services/Run/Metrics/WeeklyAggregator.php) builds the snapshots on. A webhook stub carries no `activity_details` row and so no date at all; `strava:ingest` gives it one before it can count against any recap.

### Both entry points, not just the kickoff

[KickoffWeeklyRecaps](app/Actions/AI/KickoffWeeklyRecaps.php) filters its dispatch set, and returns the deferral count alongside `dispatched`/`rule_based` so `ai:weekly-recap` can report it.

Gating only the kickoff would have bought nothing. The per-ingest cascade already stages a weekly recap row `Pending` ([DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php)), and `ai:self-heal` resumes the earliest stalled weekly link per user every hour ([SelfHealer](app/Services/AI/SelfHealer.php), via `ChainResolver::stalledWeeklyLinkPerUser`) — which would have narrated the deferred week an hour later regardless. So the sweep consults the same gate.

### The pickup path is the sweep, and needs no new scheduler entry

That same hourly sweep is what makes deferral safe: the row exists and stays `Pending` (which per the [[deferred-recap-windowing]] reading is a "recap incoming" signal, not a backlog), so the week is re-offered every hour and resumes on the first sweep after its runs finish hydrating. With the drain converging a fresh import in well under an hour, the practical delay is one sweep.

Nothing else was needed. `ai:catch-up` calls `KickoffWeeklyRecaps` too and therefore inherits the gate by construction; the deferral does not depend on it.

### The escape hatch is wall clock, and stores nothing

Past `ai.recap_hydration_grace_hours` (`AI_RECAP_HYDRATION_GRACE_HOURS`, default 48) the week is narrated anyway, against whatever has landed, and the deferral is recorded in the structured `narrator.*` log — `narrator.recap.hydration_deferred` while waiting, `narrator.recap.hydration_grace_expired` when the hatch fires.

**A counter was rejected because it would have needed state.** There is no per-user key-value store this would fit, and the alternatives were all worse than the thing they'd count: a cache key with a week-scoped TTL is a second clock wearing a counter's clothes, and putting the count on the `Analysis` row means the deferral can only be counted once a row exists — which is exactly what a deferred recap does not have on the kickoff path. Wall clock needs nothing persisted, gives the same guarantee, and is far easier to reason about at 00:01 on a Monday.

**The window is anchored at the later of the week's close and the athlete's Strava connection.** A week that closed on Sunday therefore narrates by the end of Tuesday at the latest, which is the reading the default is chosen for. The second anchor exists because a first connect backfills weeks that closed months ago: with only the week-close anchor, every one of those would already be past its grace and the guard would be inert for the case [[background-hydration-drain]] actually measured.

## Consequences

- **Enables:** a weekly recap that narrates final load, so `invalidate: false` stops being a trap. The ordering [[background-hydration-drain]] could only write down is now enforced by the recap side, without the drain having to know anything about narration.
- **Costs:** up to one hourly sweep of latency on a recap whose week is still filling in, and two extra queries per kickoff/sweep pass (the un-hydrated weeks, and the connection timestamps).
- **A first connect no longer narrates its weekly recaps in the same breath as the backfill.** [KickoffRecapsJob](app/Jobs/AI/KickoffRecapsJob.php) still runs, and its monthly half is unchanged, but every backfilled run is summary-only at that instant so the weekly half defers. The drain hydrates them within the hour, each hydration stages the week's row `Pending` through [DispatchPostRunAnalysis](app/Listeners/DispatchPostRunAnalysis.php), and the hourly sweep narrates from there. Day one, an hour or two later, on real load — which is the trade this ADR is.
- **A week can still be narrated thin**, deliberately: past the grace window, or when a run's detail attempts are spent. Both are visible in the log rather than silent.
- **Bounded by design, not by trust.** Nothing in the gate can defer forever: every path out of `awaitingHydration()` is a state the pipeline itself reaches, and the wall clock catches whatever the pipeline does not.
- **The monthly recap is unchanged.** Its window is a month, so the drain has never plausibly been mid-flight when it fires; adding the gate there would have been cost without a case.

## See also

- [[background-hydration-drain]] — the drain whose "drain first, recaps second" note this resolves
- [[deferred-recap-windowing]] — the other reason a recap waits: the period is not closed yet
- [[bounded-self-heal-and-dead-letter]] — the sweep that does the picking up
- [[chained-narration]] — the chain a resumed link walks forward
