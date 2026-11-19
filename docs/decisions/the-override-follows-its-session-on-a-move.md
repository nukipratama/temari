---
title: The override follows its session on a move
description: A move or swap that takes the week's brought-back session to another day carries its override there; a readiness override is handed back instead, since it judged one day.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-11-19
code_refs:
  - app/Http/Controllers/PlanController.php
  - app/Services/Run/Plan/SessionEditRules.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/Periodizer.php
---

# The override follows its session on a move

**Status:** Accepted (2026-11-19). Supersedes the "override day does not move" rule of [[a-manual-edit-may-stack-hard-days-with-a-warning]]. Built in #2488.

## Context

The week's one override ([[an-athlete-can-override-an-eased-quality-session]]) is stored by date on the week's adaptation (`plan_adaptations.override_date`). A move swaps what two days prescribe while each keeps its date, so moving the overridden session would have left the override on whatever landed on its old date, and the next regeneration ([PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php)) would have restored the quality there. The day was therefore frozen until the override was undone, and undoing a dropped-session override regenerates the week without the session, so an athlete who brought back a Thursday tempo had no way to run it on Friday.

## Decision

- **The override day moves like any other day.** [SessionEditRules::moves()](app/Services/Run/Plan/SessionEditRules.php) no longer reads the override: the day takes rest-day moves and swaps, and other days swap onto it, under the usual rules and hard-neighbour warning.
- **A drop override follows its session.** When a move or swap touches an open override day (today or later), [PlanController::carryOverride()](app/Http/Controllers/PlanController.php) moves `override_date` to the day the session lands on, in the swap's transaction. A weekly, budget or hard-day drop override is a claim on the session, so the brought-back session stays protected there and its undo shows there. Which side of the swap started the edit makes no difference.
- **A pinned bring-back stays exempt.** A move pins both days, so the brought-back session reaches the periodizer as a fixed day. `Periodizer::capQualityAroundWeeklyHardDays()` leaves a fixed day on the hard-day override's date out of the weekly two-hard-day count, as it already did for a generated one; it still protects its neighbours' spacing. A budget bring-back needed no change: a pinned day keeps its generated row, which `Periodizer::hardBudget()` already exempts.
- **A readiness override is handed back.** It judged one day's readiness, so a move clears it and the week's override is free again. The new day's own readiness decides whether to ease the session.
- **A passed override day is unchanged.** It can no longer be undone and is made up like any missed session; the override row stays as it was.

Rejected: keeping the freeze with a clearer refusal (the athlete still cannot run the session they brought back on the day they can), and carrying a readiness override to the new day (it would exempt a day from easing on another day's readiness).
