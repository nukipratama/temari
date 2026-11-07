---
title: A hard-day-dropped session can be brought back
description: A session the weekly two-hard-day count made easy, a fast finish included, is offered back as the week's one override, labelled as a third hard day; the count is lifted for that day only, and the two-day spacing still holds.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-11-07
code_refs:
  - app/Enums/PlanOverrideKind.php
  - app/Services/Run/Plan/QualityOverride.php
  - app/Services/Run/Plan/QualityOverrideService.php
  - app/Services/Run/Plan/Periodizer.php
  - resources/js/components/plan/DayDetail.tsx
---

# A hard-day-dropped session can be brought back

**Status:** Accepted (2026-11-07). Built in #2397.

The plan holds a week to two hard days, at least two days apart ([Periodizer::capQualityAroundWeeklyHardDays()](app/Services/Run/Plan/Periodizer.php), [[the-plan-follows-the-coaching]]). Demanding runs the athlete already did count, so two hard efforts early in a week turn the rest of it easy, the weekend's fast finish included ([[a-fast-finish-long-run-for-5k-to-half-goals]]). A fast finish dropped that way said nothing, and neither drop could be undone.

- **A dropped fast finish says why.** It now carries `Periodizer::NO_FAST_FINISH_HARD_DAYS_REASON` or `Periodizer::NO_FAST_FINISH_SPACING_REASON`, which the day reads as "no fast finish. the week already has its hard days." or "… too close to another hard day."
- **Only the count is lifted.** A day the count made easy, whether a Tempo, an Interval, a marathon-pace long or a fast finish (`Periodizer::HARD_DAYS_FULL_REASON`, `NO_FAST_FINISH_HARD_DAYS_REASON`), is offered back as the week's one override ([[an-athlete-can-override-an-eased-quality-session]]), kind `hard_day_drop`, labelled "bring it back · a third hard day this week". With `PlanInputs::hardDayOverrideDate` set, the cap skips the count for that day, but still makes it easy when it falls within a day of another hard day. Other days count hard days as if it were not there, and it still counts for their spacing. A day dropped for spacing is never offered.
- **Only what changes nothing else is offered.** The periodizer re-runs the cap with the day exempt and marks it `planned_sessions.hard_day_restorable` only when the day keeps the dose the count took and no other day's hard minutes move. The hard-minute ceiling is not lifted: the day keeps the dose the budget already left it, so the count, not the budget, is the only thing waived.
- **It is handed back once the count no longer bites,** as a budget bring-back is ([[a-budget-dropped-session-can-be-brought-back]]): `Periodizer::refundingUnneededBringBack()` clears the override while the day is open if the plan reads the same without it.
- **It undoes like the others.** "ease it again" clears the override and regenerates.

**Why the count gives and the spacing does not.** About two high-intensity sessions a week is the usual distribution for trained endurance athletes (Seiler, *IJSPP* 2010;5(3):276–291), a guideline the athlete can reasonably exceed in one week when they feel ready. Recovery after work above the first ventilatory threshold takes longer than after easy running (Seiler et al., *Med Sci Sports Exerc* 2007;39(8):1366–1373), which is what the 48-hour gap protects, so no override shortens it.

Rejected: lifting spacing as well (back-to-back hard days are the injury and staleness risk the rule exists for), and offering only fast finishes (a count-dropped tempo is the same trade).
