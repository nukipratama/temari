---
title: The plan follows the coaching, not just the arithmetic
description: Scheduled recovery weeks in a race build, quality kept off the long run's flanks, quality type chosen by race duration rather than distance, a session floor before any quality, and a threshold block that progresses by phase.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-07
code_refs:
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/WeekPlanBuilder.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/Periodizer.php
---

# The plan follows the coaching, not just the arithmetic

**Status:** Accepted (2026-09-07)

## Context

The periodizer was reviewed end to end against how a coach would actually write these
weeks, prompted by the athlete reading their own plan. The volume model held up — it
anchors on a trimmed weekly mean, caps the long run by both a race-distance band and
150 minutes on feet, and clamps today's session on readiness without rewriting the plan.
Five things did not.

## Decisions

### A race build gets scheduled recovery weeks

`PhaseSchedule::forRace()` emitted `base… build… peak… taper` with **no Deload at all**,
while Build compounded at `BUILD_WEEKLY_RAMP` — five Build weeks is **+33% with nothing
absorbing it**. The only Deload was the reactive one `Periodizer::applyDeload()` applies
to the *current* week once monotony, strain or adherence has already slipped: every
trigger is a symptom of damage done.

The self-scaled arc had a scheduled down week all along (`SELF_SCALED_CYCLE_WEEKS`). The
arc with a deadline, a rising ramp and a motivated athlete was the one without it.

Every fourth week of the Base/Build stretch is now a recovery week. Peak and Taper are
exempt — both are already reductions — and a ramp shorter than four weeks gets none,
having nothing to recover from yet. `volumeMultipliers()` now exponentiates the ramp over **build weeks counted so far**
rather than position within a contiguous run. That distinction is the whole change: a
recovery week splits the build into separate runs, and ramping within each run restarts
every one of them at 1.0 — an eight-week arc with one recovery week came out with both
its build weeks flat at 1.00, killing volume progression entirely. Counting weeks carries
the ramp across the dip.

### Quality never lands on the long run's flanks

`spreadOffsets()` maximised the gap *between* quality days and never treated the long run
as a hard day, so it pushed quality to the ends of the week. At five and six sessions
that put one quality day the day *before* the long run and the other the day *after* the
previous week's. The days either side of the long run are now removed from the quality
pool first, wrapping the week, with a fallback to the full pool when a week is too dense
to avoid them.

### Quality type follows race duration, not race distance

`MARATHON_DISTANCE_THRESHOLD_M` split "short" from "marathon", so any sub-30 km race got
Interval work. Distance cannot see the athlete. Threshold pace is roughly what can be
held for an hour: a 35-minute 10K runner races *above* threshold and needs VO2max work,
while a **70-minute** 10K runner races at or *below* it, so intervals train a pace they
will never race at.

The single quality slot now chooses on `RiegelProjector`'s projected finish — under 50
minutes leans VO2max, 70 minutes or more leans threshold, and in between the build
develops VO2max while peak and taper sharpen at race-specific threshold. **No projection
means threshold**, the safer single session and the same reasoning Base already applied.

Two quality slots are unaffected: a week with both stimuli has nothing to choose.

### A week needs three sessions before it gets any quality

Base already refused quality below four sessions. The other phases refused it nowhere, so
an explicitly-chosen two-session week came out as **one quality day plus the long run and
no easy running at all**. Quality now requires three sessions in every phase.

A floor of four was considered and rejected: it would strip quality from three-session
race builds entirely, which is a different coaching error rather than a fix.

### The threshold block progresses by phase

`INTERVAL_REP_TABLE` already developed interval work across a season (Build 3:2, Peak 4:2,
Taper 2:3). A tempo day never changed shape — always one continuous block, growing only
as weekly volume grew. `TEMPO_BLOCK_TABLE` now mirrors it: three shorter blocks in Base,
two in Build, one continuous effort at Peak, back to two in Taper so the session sharpens
without draining. A day too small to split stays continuous.

## Consequences

- **Prescribed weekly volume is unchanged by the tempo split.** The blocks and their
  recoveries are carved out of the day's existing budget, and the closing block takes the
  rounded remainder, so the segments still sum exactly to the figure on the card — the
  invariant [[a-session-is-the-whole-outing]] established, asserted across every long-run
  baseline from 3.0 to 25.0 km.
- **Recovery weeks reduce total prescribed volume across a build**, which is the point.
- **This supersedes the distance-based half of the quality-slot rule** shipped days
  earlier: a sub-30 km race no longer implies Interval on its own.
- **`Periodizer` now depends on `RiegelProjector`.** It is the only new collaborator.
- The Plan page collapses repeated work blocks into one legend entry, so a Base tempo day
  reads `3× main set` rather than listing six segments.
