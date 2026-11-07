---
title: A budget-dropped session can be brought back
description: A session the weekly hard-minute cap trimmed, or a fast finish it dropped, is offered back as the week's one override, its label stating how far over the cap it puts the week; the brought-back day sits outside the cap and the rest of the week is not trimmed to pay for it.
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

# A budget-dropped session can be brought back

**Status:** Accepted (2026-11-07). Decision #2376, built in #2380.

The weekly hard-minute cap ([[the-plan-follows-the-coaching]], locked at the week's first regeneration) can trim a quality session or drop a long run's fast finish. Such a day carries the numbers that cost it its work (`prescription_budget`), and the athlete may bring it back as the week's one override ([[an-athlete-can-override-an-eased-quality-session]]), kind `budget_drop`.

- **The offer states its cost.** [QualityOverride::forDay()](app/Services/Run/Plan/QualityOverride.php) labels it "bring it back · N hard min over this week's cap", where N is the minutes the day needed less the minutes the cap had left for it. The cap is a ceiling, not a rule the athlete can't cross, and the label makes the trade visible.
- **The day sits outside the cap.** [Periodizer::withIntensityPrescriptions()](app/Services/Run/Plan/Periodizer.php) reads the date from `PlanInputs::budgetOverrideDate`, leaves it out of the ceiling's sum and trim loop, and keeps a fast finish there whatever the ceiling says. The other days keep the doses the cap gave them; nothing is trimmed elsewhere to pay for it. The day runs the dose the uncapped plan would give it; a goal-pace session steps down to fit its distance as it always does, and nothing else does.
- **Only what the other rules allow is offered.** The hard-day spacing and count still apply to a brought-back day, as they do to every override ([[an-athlete-can-override-an-eased-quality-session]]). So [Periodizer::withIntensityPrescriptions()](app/Services/Run/Plan/Periodizer.php) re-runs them with the day at full dose and withholds the budget note, which is the offer, when that dose does not fit the day's distance, would be demoted itself, or would demote another day. A day the uncapped plan would run easy anyway is not the cap's doing, so it is not offered.
- **It is handed back once the cap no longer bites.** While the day is still ahead and unrun, a full regeneration that would plan every day the same session and hard minutes without the override clears the override, so the week's one override is free again, as a lapsed weekly-drop restore is.
- **It undoes like the others.** "ease it again" clears the override and regenerates, so the cap applies to the day again.

Rejected: raising the cap by the brought-back minutes (the wallet would stop reading against a fixed number), and trimming the rest of the week to fit (the athlete asked for one session, not a different week).
