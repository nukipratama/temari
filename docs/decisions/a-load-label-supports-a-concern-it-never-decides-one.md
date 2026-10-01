---
title: A load label supports a concern, it never decides one
description: The CTL/ATL form label no longer forces rest or a whole-week deload and is unknown for its 42-day warm-up; its threshold is continuous in CTL; the personal-range and volume guards measure against the prescription; readiness and adaptation read load as unknown while the window awaits hydration.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-02
code_refs:
  - app/Services/Run/Metrics/Readiness.php
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Services/Run/Story/BriefingContext.php
  - app/Services/Run/Plan/ReadinessClamp.php
---

> **Partly superseded (2026-10-02) by [[monotony-and-strain-describe-a-week-they-never-deload-it]].** Strain is no longer a deload trigger, so decision 5's strain-ratio warm-up guard is gone. The hydration gate stands, as does the rest of this decision.

# A load label supports a concern, it never decides one

**Status:** Accepted (2026-10-02)

## Context

The 2026-10-01 coaching audit executed the readiness rules against synthetic athletes:

- **The form label forced rest.** `overreaching` (form below −2T) capped the day at Rest, and on a Monday turned the whole week into a deload. A steady two-run beginner read `overreaching` after one 65–85-minute easy run. The stepwise threshold (T = 5 / 15 / 20 by CTL band) was non-monotone: after TRIMP 170 the next morning read `overreaching` at CTL 19.9, while TRIMP 180 read `optimal` at CTL 20.1.
- **History that starts mid-training read as overload.** The EWMA starts at zero on the first scored day. A perfectly steady four-run athlete whose heart rate first appears today got 36 `overreaching` days and five whole-week deloads with no change in load.
- **The guards capped the plan's own progression.** The personal-P75 guard compared raw TRIMP with a P75 rounded to tens, across windows that included the current one, so a steady 592-TRIMP week read "above range" every day. The week-to-date volume guard compared against last week, so the week after every scheduled deload (+54% to +65%) tripped it.
- **Production** (read-only aggregates over three non-demo athletes): both stored low-readiness deloads that opened race seasons were decided while only a quarter to two fifths of the athlete's runs had been analysed during onboarding backfill. With the full history, the same moments read fresh or fatigued. `PlanAdapter::forWeek()` was the one decision path that skipped the hydration gate the plan page already used.

## Decision

1. **A form label is never a strong concern.** `fatigued` and `overreaching` set no ceiling of their own. They count only as supporting load, which lets a mild reported concern become a ModerateOk advisory. Rest is reserved for reported concerning pain or illness, so a load label can no longer produce the LowReadiness whole-week deload. Overreaching is a performance-based diagnosis that no load number makes ([[coaching-evidence#Meeusen2013]]), and self-report tracks load better than objective markers ([[coaching-evidence#Saw2016]]). **Evidence-supported.**
2. **Form is unknown for its warm-up.** Until 42 days (the CTL time constant) of scored history follow the first scored day, `form_status` is null, and so is each such day in the trend. The summary carries `form_known_from`. Unknown is not fatigue. **Heuristic** ([[coaching-evidence#AllenCoggan]], [[coaching-evidence#Hellard2006]]).
3. **The threshold is continuous in CTL**: piecewise linear through (10, 5), (30, 15) and (60, 20), flat outside. This keeps the old band values near each band's centre and removes the cliff. Adding TRIMP on the last day lowers form by about 0.11 per unit while the threshold rises at most 0.012, so more load never reads fresher; a property test covers four histories, including one just under the old CTL-20 edge. **Heuristic**: the constants and bands are conventions with unstable fitted parameters ([[coaching-evidence#Vermeire2022]], [[coaching-evidence#Imbach2022]]).
4. **The guards measure against the prescription.** The volume guard compares actual km-to-date with prescribed km-to-date this week, sized the way grading sizes a day, and fires above 15%. With no planned week it has nothing to compare and stays silent. The personal-range guard compares unrounded weekly TRIMP with the P75 of the eight windows before the current one, and fires only when the athlete is also ahead of the prescription, or has none. Rounding stays display-only. Week-to-week ratios and acute:chronic spikes do not protect runners ([[coaching-evidence#Frandsen2025]], [[coaching-evidence#Nakaoka2021]], [[coaching-evidence#Impellizzeri2020]], [[coaching-evidence#Buist2008]]), and the IOC consensus gives no thresholds ([[coaching-evidence#Soligard2016]]). **Evidence-supported.** The 15% margin itself is a heuristic.
5. **Partial history is unknown.** `PlanAdapter::forWeek()` applies the same gate as the plan page: while any run in the 42-day window awaits hydration, it reads no load and decides readiness as history loading. During the form warm-up it also treats the CTL-relative strain ratio as unknown, since a CTL still climbing from zero makes any steady week look like excess strain. **Product choice.**

The week-over-week volume figure stays in the briefing as narration context; it no longer gates anything. The new reason code `running_ahead_of_plan` replaces `volume_increased_sharply`. The old codes and their copy remain so recorded decisions still render.

## Consequences

- Synthetic fixtures now pass: a steady two-run beginner after one 65-minute easy run (no Rest, no deload); a steady four-run athlete whose heart rate starts today (no Rest or deload in weeks 1–6); a steady 592-TRIMP week (not above range); a compliant post-deload Build week (QualityOk); and a race season opened with a quarter of runs analysed (no LowReadiness deload).
- A new athlete sees no form verdict for six weeks.
- The prescription read adds one query to Home and the deferred Plan props.
- Monotony and strain deloads for six-session and daily runners are CR-04's (#1517). User-facing names for these numbers are CR-07's (#1520).
- Supersedes rule 5's "an overreaching form … still deloads the current week" in [[a-race-block-never-prescribes-below-habit]].
