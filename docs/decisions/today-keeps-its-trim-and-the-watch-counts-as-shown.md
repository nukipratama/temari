---
title: Today keeps its easy-day trim, and the workout on the watch counts as shown
description: An unpinned easy day still being trimmed is trimmed on its own day too, and each workout the intervals.icu sync sends is recorded as shown advice, so a day is graded on what the watch carried.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-11-01
code_refs:
  - app/Services/Run/Plan/CurrentWeekVolumeProjector.php
  - app/Services/Run/Plan/VolumeRedistributor.php
  - app/Services/IntervalsIcu/IntervalsIcuSync.php
  - app/Services/Run/Plan/RecommendationHistory.php
---

# Today keeps its easy-day trim, and the workout on the watch counts as shown

**Status:** Accepted (2026-11-01). Amends the "today's fixed session" clause of [[no-automatic-mileage-debt]] and the source of shown advice in [[grading-follows-shown-advice-and-actual-stimulus]].

## Context

In a deload week on prod, an athlete overran two easy days. The Saturday easy run, asked at 3.1 km, was trimmed to 2.7 km on Thursday's view and would have read 2.2 km on Friday's. On Saturday it read 3.1 km again: [CurrentWeekVolumeProjector::project()](app/Services/Run/Plan/CurrentWeekVolumeProjector.php) trimmed only days after today and counted today at its full ask. Today, Plan and the intervals.icu workout all said 3.1 km.

The run was graded against 2.7 km. [RecommendationHistory::beforeRuns()](app/Services/Run/Plan/RecommendationHistory.php) reads the last advice viewed in the app before the run, and the athlete had last opened the app on Thursday. The watch workout, the advice they actually followed, was never recorded.

## Decision

**Today is trimmed like the rest of the week.** An unpinned easy day that is today joins the days `VolumeRedistributor` scales, as its own docblock already described ("remaining unpinned non-past training days"). The scale reads km completed through yesterday, so it holds all day, and today's own run never moves it. Today stays a fixed contribution when the readiness clamp eases it (recorded or not), when it is pinned, and when it is a key session. The trim is easy running absorbing the week's surplus ([[easy-running-absorbs-the-week]]). The surplus that called for it is still there on the morning of the run, so the trim stays.

**The workout on the watch is shown advice.** Each time `IntervalsIcuSync` creates or updates today's event, it records the day's recommendation token as a [RecommendationView](app/Models/RecommendationView.php) at the send time. A later view in the app still wins, because grading reads the last advice shown before the run, from either surface. An unchanged payload sends nothing and records nothing. A pinned or Race day that keeps its prescription beside an advice note records nothing either: the watch carries the prescription, and the day's token carries the advice.

## Consequences

- Several easy days left in a week now share a trim that used to fall on the later ones alone. The week total is unchanged, since today counted its shown km already ([[the-week-total-is-the-week-the-plan-asks-for]]).
- A day graded on a sent workout is graded on the km the watch carried, not on an older view in the app.
- The browser and the sync decode the token through one helper, `RecommendationHistory::advice()`.
