---
title: The week total is the week the plan asks for
description: The current week's "/ X km" is the week as it ends if the athlete follows the plan from today, with credited km on past days, today's run or shown km, and the shown km of later days, from one source that Home, Plan and the narrator all read.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-08
code_refs:
  - app/Services/Run/Plan/CurrentWeekKm.php
  - app/Services/Run/Plan/CurrentWeekPlanBuilder.php
  - app/Services/Run/Plan/SeasonSummaryBuilder.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/CurrentWeekVolumeProjector.php
  - app/Services/AI/Agent/Tools/PlanContextTool.php
---

# The week total is the week the plan asks for

**Status:** Accepted (2026-10-08). Partly supersedes decision 4 of [[the-advised-session-leads-every-day]].

## Context

The current week's km had three definitions that disagreed, and one of them reached narration (#1994).

- **[CurrentWeekVolumeProjector](app/Services/Run/Plan/CurrentWeekVolumeProjector.php)** holds the week at the sum of every day's unscaled core km, and trims the remaining easy days to stay on it.
- **The Today and Plan headers** summed the days' shown `distance_km`. That counted a past day at its prescription and a trimmed day after its trim. A 0.6 km overrun on a past make-up day was taken off Saturday and never credited to the day it was run, so the header read 16.0 km against a 16.6 km week.
- **The narrator's `get_planned_sessions`** gave today and future days their unredistributed core km. It could quote Saturday as 2.7 km while the page showed 2.1.

## Decision

**The current week's "/ X km" is the week as it ends if the athlete follows the plan from today.** It adds up three parts:

- the credited km of every past day, which is the km actually run;
- today's credited km once a run is recorded, and otherwise its shown km after any ease or clamp;
- the shown, redistributed km of every later day.

Under [[no-automatic-mileage-debt]], `VolumeRedistributor::MAX_SCALE` caps every easy day at its own ask, so missed km are never made up. A total that still counted a missed day's planned km would be a target the plan never asks the athlete to reach. When the trim fully absorbs an overrun, this total equals the projector's week target.

**One source.** [CurrentWeekKm::forUser()](app/Services/Run/Plan/CurrentWeekKm.php) returns the shown km per date and the total. It sizes each day with [PlanRenderer::shownDay()](app/Services/Run/Plan/PlanRenderer.php), the same rule `dayPayload()` renders, and counts each run with `PlanRenderer::creditedKmOf()`. [CurrentWeekPlanBuilder](app/Services/Run/Plan/CurrentWeekPlanBuilder.php) reads `planned_km_this_week` and `planned_km_eased_from` from it, and [SeasonSummaryBuilder](app/Services/Run/Plan/SeasonSummaryBuilder.php) reads them through the builder. [PlanContextTool](app/Services/AI/Agent/Tools/PlanContextTool.php) reads the shown km for today and the later days of the current week, so the narrator quotes the km the page shows. Later weeks keep their own figures.

## Consequences

- The per-day display is unchanged: a past day still reads "5.0 of 4.4", and a trimmed day still says what it was trimmed from.
- A missed day lowers the total by its km.
- A floor-bound trim (scale 0.7) cannot absorb a large overrun, so the total then ends above the projector's target.
- A past day's recorded ease no longer moves the total, because that day counts what was run. `planned_km_eased_from` adds back only the eases of today and later days.
- The narrator's payload values change and its prompt strings do not.
