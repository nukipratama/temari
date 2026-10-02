---
title: Post-race recovery follows known race load
description: Recovery after a race is derived live on every regeneration from the latest race in the past 14 days with known load, sized by the distance actually run, and applies to whichever arc follows.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/PostRaceRecovery.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/RaceOutcomeService.php
---

# Post-race recovery follows known race load

**Status:** Accepted (2026-10-02). Supersedes the recovery clause of [[a-race-outcome-is-confirmed-not-assumed]] and the self-scaled-only, one-week-for-every-distance rule of [[a-closed-race-earns-a-recovery-week]].

## Context

Recovery was frozen on the season as `opens_with_recovery` when the next season was created, and only for a race confirmed before that moment. A confirmation that arrived after the next season existed never reached the plan, so an athlete who confirmed a marathon on Tuesday still got a full build week. The rule also gave one easy week to every distance and applied only to the self-scaled arc.

## Decision

1. **Recovery is derived on every regeneration.** [PlanInputsGatherer::postRaceRecovery()](app/Services/Run/Plan/PlanInputsGatherer.php#L127) takes the latest race in the past 14 days with known load. Known load is a confirmed outcome, whose distance is its linked run's distance, else the race distance, or an owned race-day run matching the event within the existing matcher tolerance. A did-not-run or cancelled answer always wins over a matching run. Nothing is stored on the season, so a late confirmation or a correction changes the next plan.
2. **The window follows the distance actually run.** [PostRaceRecovery::after()](app/Services/Run/Plan/PostRaceRecovery.php#L28) sizes it:
   - 30 km or more ([MARATHON_CLASS_M](app/Services/Run/Plan/PostRaceRecovery.php#L17)): 14 days with no quality, plus the first full Monday-start week after race day at the Deload multiplier. **Evidence-supported** ([[coaching-evidence#Sherman1984]], [[coaching-evidence#Warhol1985]], [[coaching-evidence#MartinezNavarro2021]]).
   - Over 15 km ([HALF_CLASS_M](app/Services/Run/Plan/PostRaceRecovery.php#L19)): 7 days with no quality, at normal volume. **Heuristic**: the research found no recovery timelines for the half marathon or shorter.
   - 15 km or less: 3 days with no quality. **Heuristic**, same gap.
   - The 30 km and 15 km class thresholds are **Heuristic**.
3. **Any arc applies it.** [Periodizer](app/Services/Run/Plan/Periodizer.php#L471) turns a quality session inside the window into an easy day ("easy while recovering from the race") and [applies the deload week](app/Services/Run/Plan/Periodizer.php#L771) to the week it names, unless that week is a taper. It works on the self-scaled arc and on a new race block alike, so a block opening inside the window starts with it.
4. **Recording an outcome regenerates the plan.** [RaceOutcomeService](app/Services/Run/Plan/RaceOutcomeService.php#L84) requests a regeneration after every change. It is idempotent: recovery is a function of the race and the day, and no season is replayed.
5. **`opens_with_recovery` is retired.** Nothing reads or writes the flag. The column stays and dropping it is a follow-up.

The evidence is on the marathon only. Strength was still below baseline on day 7, and easy running in week 1 slowed its recovery against rest in 10 runners ([[coaching-evidence#Sherman1984]]). Running from 48 h did not worsen muscle-damage markers in 64 runners ([[coaching-evidence#MartinezNavarro2021]]), and muscle was still repairing after 3 to 12 weeks ([[coaching-evidence#Warhol1985]]). Hence no hard sessions for 1 to 2 weeks after a marathon, with easy running allowed.

## Consequences

- A marathon confirmed after the next season was created still gets its 14 quality-free days and its deload week.
- A half marathon no longer costs a deload week; it costs a week without quality.
- A race the athlete skipped, or one with no matching run and no confirmation, leaves training normal.
- Existing `opens_with_recovery` values are ignored.
- Partly supersedes [[a-race-outcome-is-confirmed-not-assumed]] and [[a-closed-race-earns-a-recovery-week]].

## See also

- [[plan-periodizer]], [[coaching-evidence]]
