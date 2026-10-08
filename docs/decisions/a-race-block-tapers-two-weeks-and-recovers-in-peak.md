---
title: A race block tapers two weeks and recovers in Peak
description: Every race up to 25 km tapers for two weeks, the every-fourth-week recovery week runs through Base, Build and Peak with Taper exempt, and a time trial due in a scheduled recovery week falls due the week before it.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-08
code_refs:
  - app/Services/Run/Plan/PhaseSchedule.php
  - app/Services/Run/Plan/TimeTrialSchedule.php
  - app/Services/Run/Plan/Periodizer.php
---

# A race block tapers two weeks and recovers in Peak

**Status:** Accepted (2026-10-08)

## Context

The final audit (#1815) found two problems at the end of a race block (#1923, #1924).

- **A 5K or 10K tapered for race week only.** `PhaseSchedule::taperWeeksForDistance()` gave every race up to 15 km one taper week, on no cited source. That week is race week itself, so the last full training week was the block's biggest (1.40× in a 16-week block).
- **Peak ran four to five weeks at the top multiplier with no recovery week.** [[the-plan-follows-the-coaching]] exempted Peak from the every-fourth-week recovery week because Peak was then 0.92 of Build, a reduction already. Rule 3 of [[a-race-block-never-prescribes-below-habit]] then made Peak hold the Build level, so Peak became the block's highest-volume stretch, and that ADR did not revisit the exemption. A full 5K or 10K block ran seven weeks without a down week.

## Decision

**1. Every race up to 25 km tapers for two weeks; above 25 km it stays three.** [PhaseSchedule::taperWeeksForDistance()](app/Services/Run/Plan/PhaseSchedule.php) returns 2 up to `RaceSupport::MARATHON_CLASS_ABOVE_M` and 3 beyond. The two-week taper takes the trailing slice of `TAPER_REDUCTION_CURVE`, 0.6× and then 0.4× of the build level, as the half marathon already did. The extra taper week comes off the end of the weeks before it, and `raceBlock()`'s 25% Peak / 45% Build split re-allots the rest. Block length (16 or 20 weeks) is unchanged. Both meta-analyses found the largest effect for a taper of about two weeks with volume cut 41–60%, and neither tied a shorter taper to a shorter race ([[coaching-evidence#Bosquet2007]], [[coaching-evidence#Wang2023]]).

**2. The every-fourth-week recovery week runs through Base, Build and Peak.** [PhaseSchedule::withScheduledDeloads()](app/Services/Run/Plan/PhaseSchedule.php) counts its cadence across the three phases as one run, and Taper stays exempt. A slot that would land on the last week before Taper moves one week earlier, so Taper always follows a Peak week. This extends the rule that a recovery week never takes the ramp's last week. A recovery week inside Peak runs at the build level × (1 − `DELOAD_REDUCTION`), and the Peak weeks after it return to the build level. No experiment fixes a cycle length, so the 3:1 cadence is a convention, and the app applies its own convention consistently across the block ([[coaching-evidence#Kiely2018]]). The exemption fell because its premise, that Peak is a reduction, stopped being true when rule 3 of [[a-race-block-never-prescribes-below-habit]] made Peak hold the build level.

**3. A time trial due in a scheduled recovery week falls due the week before it.** With the cadence through Peak, the week four before race week, when [[a-time-trial-every-six-weeks]] puts the last trial, is a recovery week in every 8, 12, 16 and 20-week block. The three weeks after it are trial-free, so moving the trial forward lost it. [TimeTrialSchedule](app/Services/Run/Plan/TimeTrialSchedule.php) now takes the arc's Deload weeks from [Periodizer::rowsFor()](app/Services/Run/Plan/Periodizer.php) and moves each due week that is one of them back one week. This mirrors rule 2's slot move. If that week cannot take the trial either, the existing rule applies unchanged: the trial moves to the next week of its cycle, which for the last trial means none. The move is a heuristic.

## Consequences

- A 16-week 5K, 10K or half block now runs `base ×3, deload, build ×3, deload, build ×2, peak, deload, peak ×2, taper ×2`. Its build level is 1.34×, and the taper sits at 0.80× and 0.53×. No stretch runs longer than three weeks without a recovery or taper week. A 20-week marathon block now holds a recovery week at index 15, inside Peak.
- The build level rises in a 12-week block, from 1.16× to 1.24×. The long run reaches it, and the 30-day progression cap holds it there ([[recent-single-run-cap-stages-long-run-progression]]). The progression cap outranks the volume floor, so a block whose long run that cap holds averages under the floor by exactly the long-run km the cap removed (#1998 tracks a solver that would see the cap).
- A Peak recovery week carries no goal-pace work, as any scheduled recovery week already did ([[goal-pace-work-in-the-last-weeks]]).
- The last trial of a full block now falls five weeks before race week, inside the 10K goal-pace window, where it takes the goal-pace session as before.
