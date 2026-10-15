---
title: Session intent reads the plan before the heart rate
description: A run's intent comes from its Strava tag, then the day's settled planned session, then a rep structure in its laps, and only then the heart-rate zone mix.
tags: [decision, run, ai]
status: accepted
reviewed: 2026-10-15
code_refs:
  - app/Services/Run/Metrics/SessionIntent.php
  - app/Services/Run/Metrics/IntervalDetector.php
  - app/Services/Run/Plan/PlannedSessionTypes.php
  - app/Services/AI/Agent/Tools/EffortContextTool.php
  - app/Services/AI/MaterialFingerprint.php
---

# Session intent reads the plan before the heart rate

**Status:** Accepted (2026-10-15)

## Context

[SessionIntent::forDetail()](app/Services/Run/Metrics/SessionIntent.php) tells the run narrators whether a run was meant to be hard, so high heart rate and decoupling on a quality session read as the work landing rather than fatigue. It read only the Strava `workout_type` tag and then the heart-rate zone mix. Athletes rarely tag runs in Strava, so in practice the zone mix decided, and a planned tempo run whose warm-up and cool-down diluted the zones was classed `easy`. In prod, a 10-lap planned tempo with its reps faster than threshold reached the narrator as an easy run, and the narration judged it by easy-run standards.

The effort colour already reads the plan first: [RunEffort::forDetails()](app/Services/Run/Metrics/RunEffort.php) maps the day's settled planned type through `Effort::fromSessionType()` and falls back to `SessionIntent` only on unplanned days. The narrator's intent was the odd one out.

## Decision

**Intent reads four sources in order, and the first that speaks wins:**

1. **The Strava tag** (race, long run, workout). The athlete said what the run was.
2. **The day's settled planned type**, from [PlannedSessionTypes::forRun()](app/Services/Run/Plan/PlannedSessionTypes.php): tempo and interval read `workout`, race reads `race`, long reads `long_run`. "Settled" is the type after a recorded ease, so a tempo eased to easy is an easy day here. Days with two runs carry no planned type, as `PlannedSessionTypes::sessionsByDate()` already rules, since neither run can claim the session.
3. **A rep structure in the laps**, via [IntervalDetector::detect()](app/Services/Run/Metrics/IntervalDetector.php) on `IntervalDetector::lapPaces()`, reads `workout`. This catches reps run on an easy, eased or unplanned day.
4. **The heart-rate zone mix**, unchanged (`HARD_SHARE_MIN`, `THRESHOLD_SHARE_MIN`). A continuous tempo block on an easy day lands here: the 1 Oct eased tempo in prod read `workout` this way.

An easy planned day that none of the later sources overturn reads `easy` with source `planned`, including a run with no zone data, which used to read `unknown`.

No new threshold is introduced: the lap rule reuses `IntervalDetector::REP_PACE_GAP_SEC`, and the zone rule keeps its constants. `lapPaces()` moves from a private helper on [SessionIntentJudge](app/Services/Run/Plan/SessionIntentJudge.php) onto `IntervalDetector`, so the judge and the intent read laps the same way.

**Where it applies.** [EffortContextTool](app/Services/AI/Agent/Tools/EffortContextTool.php) hands the plan-aware intent to the narrators, and [MaterialFingerprint](app/Services/AI/MaterialFingerprint.php) folds it into the run's fingerprint, so a plan that lands after a run was narrated invalidates that narration.

**Where it does not.** Post-run mood in [Temari](app/Services/Run/Story/Temari.php) keeps calling `SessionIntent::isIntendedHard()` without the plan. Mood already reads the plan through its own quality-day rule, and there `isIntendedHard()` is evidence of how the run actually went: making it plan-aware would read every planned tempo as "went after quality" whatever happened (`TemariTest`, #1527).

## Consequences

- The narrator's quality-session rules in `RunInsightNarrator` now fire on planned tempo and interval days, whatever their zones show.
- The menu verdict itself is unchanged: [SessionIntentJudge](app/Services/Run/Plan/SessionIntentJudge.php) already grades all of a day's runs against the effective session, and `IntentOutcome` already words it. Feeding that verdict to every narrator is the next layer of #2138, not a second judge.
- Changing a plan after a run was narrated changes that run's fingerprint, so a later re-read narrates it again.

Related: [[a-day-is-graded-on-distance-and-intent]], [[grading-follows-shown-advice-and-actual-stimulus]].
