---
title: A time trial every six weeks, auto-confirmed as evidence
description: About every six weeks a 5K or 10K time trial replaces the week's first quality session; a run on the day, or its trial split, that reaches the distance and the aim or effort zone becomes confirmed evidence at once, any other run gets one ask, and a skipped trial is offered once more the week after.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-05
code_refs:
  - app/Services/Run/Plan/TimeTrial.php
  - app/Services/Run/Plan/TimeTrialSchedule.php
  - app/Services/Run/Plan/TimeTrialService.php
  - app/Services/Run/Metrics/RunDistanceTimes.php
  - app/Services/Run/Plan/Periodizer.php
  - app/Services/Run/Plan/PlanInputsGatherer.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Console/Commands/Run/TimeTrialSettleCommand.php
  - app/Notifications/TimeTrialNotification.php
  - app/Http/Controllers/TimeTrialAnswerController.php
  - resources/js/components/home/TimeTrialPrompt.tsx
  - resources/js/lib/plan.ts
  - tests/Feature/Plan/TimeTrialPlanTest.php
---

# A time trial every six weeks, auto-confirmed as evidence

**Status:** Accepted (2026-10-05). Decision #1803, layer 4 of #1804. Extends [[supported-race-time-from-recent-efforts]], whose supported time now gets fresh confirmed evidence on a schedule, and reuses the ask pattern of [[a-race-outcome-is-confirmed-not-assumed]].

## Context

The supported time rests on the athlete's recent whole-run hard efforts. An athlete who never races and never runs a distance record has nothing recent to rest it on, so it ages into `stale`, and unconfirmed records only lift it a VDOT a week. A planned all-out effort at a standard distance gives the model a fresh, confirmed point without asking the athlete to vouch for arbitrary runs.

## Decision

1. **Distance.** 5K, or 10K when the race goal is a half marathon or longer, marathon class and ultras included ([TimeTrial::distanceFor()](app/Services/Run/Plan/TimeTrial.php#L50)). A season with no race always runs 5K. "Half or longer" is every race over 10K, the same split goal-pace work uses ([GoalPaceWork::kindFor()](app/Services/Run/Plan/GoalPaceWork.php#L83)).
2. **Cadence, race seasons.** Counted back from race week: the last trial falls 4 weeks before race week, then every 6 weeks earlier, inside the season ([TimeTrialSchedule::dueWeeks()](app/Services/Run/Plan/TimeTrialSchedule.php#L48)). Never in the last 3 weeks before the race. **Heuristic**: the 6-week cadence and the 3-week exclusion are named constants with no trial behind them.
3. **Cadence, seasons with no race.** The first trial in the season's third week, then every 6 weeks.
4. **Recent evidence skips a trial.** Confirmed evidence or an unconfirmed hard effort within ±10% of the trial distance, dated from 4 weeks before the trial's due week, skips that cycle's trial ([TimeTrialSchedule::forWeek()](app/Services/Run/Plan/TimeTrialSchedule.php#L70)). **Heuristic.**
5. **Placement.** A trial replaces the week's first Tempo or Interval that is still prescribed as quality ([Periodizer](app/Services/Run/Plan/Periodizer.php#L511)), so the hard-day count never rises; the week-level hard-minute ceiling shrinks the other sessions rather than the trial. A trial due in a deload week, a week with no quality slot, or a week where the hard-day spacing would drop it moves to the next week of its cycle, and the move does not use the retry. In a goal-pace week ([[goal-pace-work-in-the-last-weeks]]) the trial takes the goal-pace session and that week has no goal-pace work. Trials form their own `time_trial` family and never teach or learn progression.
6. **The trial day.** Sized like race day ([[a-session-is-the-whole-outing]], `SegmentGenerator::raceSegments`): the trial distance alone, one segment at the aim, with no warmup carved into its distance or bar graph ([TimeTrial::dayKm()](app/Services/Run/Plan/TimeTrial.php#L110)). It is sized from the trial rather than from the slot it replaced, which is why every caller passes the race context into `SegmentGenerator::coreKmFor()`. The aim is the supported time at the trial distance: the supported VDOT read back at that distance, the same one number every pace comes from. The day carries one hint: "warm up first like you would before a race, then record the trial as its own run if you can." ([sessionHint()](resources/js/lib/plan.ts#L425)).
7. **Auto-confirm gate.** On the trial day, a run counts when its distance is within ±10% of the trial distance and either its time, scaled to the trial distance, is no slower than the aim plus 5%, or its average heart rate is in zone 4 or above ([TimeTrial::gate()](app/Services/Run/Plan/TimeTrial.php#L176)). Heart rate only gates effort; it never becomes a time. A passing run is written as `PerformanceEvidenceKind::Test` evidence through `PerformanceEvidenceRecorder`, with no question ([TimeTrialService::settle()](app/Services/Run/Plan/TimeTrialService.php#L28)). **Heuristic**: the ±10%, +5% and zone 4 are named constants.
8. **The trial split.** A run longer than the ±10% band, typically the warmup and the trial recorded as one run, is read by its best split at the trial distance ([TimeTrial::reading()](app/Services/Run/Plan/TimeTrial.php#L126), from `RunDistanceTimes::bestSplit()`): the split time goes through the same gate, with the split's heart rate, or the whole run's average when the splits carry none; the intent evidence records which (`time_trial_read`, `time_trial_heart_rate_read`). Confirmed evidence, auto or by "yes", is then the split time at the trial distance, never the whole run. This is a narrow exception to the whole-run rule of [[supported-race-time-from-recent-efforts]], for planned trial days only; everywhere else evidence stays whole-run. A run shorter than the band, or a longer one with no split at the trial distance, is read whole and so is asked about. **Heuristic.**
9. **Ask path.** A trial with a run on the day that fails the gate gets one ask: an inbox entry and a push, quiet hours applying through the shared hold ([TimeTrialNotification](app/Notifications/TimeTrialNotification.php)), and a card on Home ([TimeTrialPrompt](resources/js/components/home/TimeTrialPrompt.tsx)). "Yes, count it" writes `Test` evidence from the reading of the day's run closest to the trial distance; "No, it wasn't all-out" closes it. One ask per trial, no reminder. `plan:settle-time-trials` settles each finished trial of the last 7 days once, at 09:05, and skips the demo athlete ([TimeTrialSettleCommand](app/Console/Commands/Run/TimeTrialSettleCommand.php#L23)).
10. **Single retry.** A trial counts as skipped when the athlete excused it, nothing was run on its day, or readiness eased it to an easy run or a rest ([TimeTrial::countsAsSkipped()](app/Services/Run/Plan/TimeTrial.php#L196)). It is then offered once more in the following week's first quality slot, never inside the last 3 weeks before a race and never in a deload week; when that week cannot hold it, the retry is gone. A trial that was run and answered "no" is not retried, and a skipped retry is not offered again.
11. **Grading.** A trial day is credited on its whole-day distance, since the warmup is often recorded apart ([SessionMatcher::creditedKm()](app/Services/Run/Plan/SessionMatcher.php#L267)), capped at the trial distance so the warmup never reads as overreaching ([SessionMatcher::scoreRange()](app/Services/Run/Plan/SessionMatcher.php#L84)), and its intent is the gate: hit when a run passed, missed otherwise ([ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php#L267)). It is never judged against its pace segments.
12. **What the athlete sees.** The day reads "time trial", derived from the persisted `prescription_race_context` and not from a new session type, on Home's today session, the day detail, the week strip and the bar graph ([PlanRenderer::timeTrialOf()](app/Services/Run/Plan/PlanRenderer.php#L495), [sessionLabel()](resources/js/lib/plan.ts#L381)), with "aim around 23:30. checks your fitness so your paces stay honest." The plan tools hand narrators the trial's distance and aim.

## Consequences

- A trial week has the same hard days as before; its volume can move by the difference between the replaced slot and the trial distance, and the athlete's warmup is on top of it, as on race day.
- A season without a supported time has no aim, so it gets no trials until one exists.
- An athlete who records the warmup and the trial as one run is read by the trial split; only a run with no split at the trial distance is asked about whole, and "yes" then records that whole run.
- Rollout: the owner runs `plan:regenerate` for all users after deploy. Past days are untouched, so no regrade is needed.

## See also

- [[plan-periodizer]], [[coaching-evidence]], [[notification-inbox]]
