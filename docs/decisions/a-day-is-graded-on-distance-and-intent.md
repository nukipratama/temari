---
title: A day is graded on its distance and on the session's intent
description: A deterministic intent verdict (hit, missed, too hard, unknown) is folded into a credited day's status and score, while the weekly adaptation keeps reading distance alone.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-17
code_refs:
  - app/Services/Run/Plan/SessionIntentJudge.php
  - app/Enums/IntentVerdict.php
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/PlanAdapter.php
  - app/Console/Commands/Run/RegradeSeasonCommand.php
  - database/migrations/2026_09_17_000000_add_distance_score_to_planned_sessions_table.php
  - resources/js/lib/plan.ts
---

# A day is graded on its distance and on the session's intent

**Status:** Accepted (2026-09-17). Builds on [[a-credited-day-shows-its-result]] and [[the-eased-session-leads]].

## Context

A day's `status` and `compliance_score` were a km ratio and nothing else. An easy jog at full
distance on a tempo day read `done`, and a tempo day eased to easy and run easy read the same as one
run at threshold. The chip said whether the athlete covered the ground, never whether the run did
the job the session was written for.

## Decision

**Code decides, per credited day.** [SessionIntentJudge](app/Services/Run/Plan/SessionIntentJudge.php)
returns one [IntentVerdict](app/Enums/IntentVerdict.php) (`hit`, `missed`, `too_hard`, `unknown`)
plus the numbers that justify it, so a narrator can phrase them later. It is pure: the
[ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php) hands it the effective session's
segments, the athlete's paces as of that day (`VdotEstimator` through `TrainingPaceCalculator`, the
same bands the prescription used) and the day's runs. The prescription is rebuilt from the core km the
day was graded against through `SegmentGenerator::forCoreKm()`, and an eased day is judged as the easy
block it became. Only a day already credited on distance is judged; a missed, excused or rest day
never is. With no VDOT estimate, no run, or a rest or race day, the verdict is `unknown`.

**Pace first, heart rate can rescue.** The tolerance is 10 s/km either way.

- **Tempo.** On the day's longest run, the best-effort window matching one prescribed block must sit
  within tolerance of the block's pace. The window is the longest one the stream producer records
  that fits inside the block, so a 26-minute block reads the 20-minute window: demanding a longer
  window than the block would average in the warm-up. Failing that, heart rate rescues when the
  minutes at or above the block's zone cover one block. No window and no heart rate is `unknown`.
- **Intervals.** On the longest run's laps, when they are not the watch's plain kilometre grid and
  `IntervalDetector` finds a fast/slow structure, all but one prescribed rep (at least one) must sit
  within tolerance of interval pace. Otherwise the rep-length window stands in: reaching pace there is
  `hit`, since a kilometre grid cannot count reps, and not reaching it is `missed`. Heart rate rescues
  when the minutes at or above the rep zone cover the reps needed.
- **Easy and long.** The day's pace over every run, grade-adjusted wherever `gap_pace` was recorded,
  is `too_hard` only when faster than marathon pace; a long run prescribed at marathon pace gets the
  tolerance first. Heart rate rescues when no more than `PlanAdapter::EASY_DAY_HARD_SHARE` of the
  zone time sat above the prescribed zone, and confirms `too_hard` otherwise.
- **Race and rest** keep their distance-only behaviour, and a run on an excused day stays `ran_anyway`.

**How the two combine** ([SessionMatcher::withIntent()](app/Services/Run/Plan/SessionMatcher.php)).
The distance verdict comes first, and the intent can only move a credited day between credited
statuses, so the upward-only crediting at ingest is untouched.

| Distance verdict | Intent `hit` / `unknown` | Intent `missed` | Intent `too_hard` |
|---|---|---|---|
| `missed` | `missed` | `missed` | `missed` |
| `partial` | `partial` | `partial` | `overreached` |
| `done` | `done` | `partial`, score capped at 84 | `overreached` |
| `overreached` | `overreached` | `overreached` | `overreached` |

- An intent miss caps the score at 84, the top of the partial band, so a `done` distance that missed
  its session never reads as done by number either. It never raises a lower score.
- Running too hard keeps the distance score: the ground was covered, and the status carries the
  signal.
- Well past on distance stays `overreached` even when the intent was missed. Extra volume is the
  louder signal of the two.

**The weekly adaptation keeps reading distance.** Every graded row also stores `distance_score`, the
plain km ratio. `PlanAdapter::previousWeekAdherencePct()` averages that, so a week of runs that were
too easy never reads as a missed week and backs the plan off. `RanTooHard` keeps its own zone-share
rule. The season header and the Plan page's week adherence read the widened `compliance_score`, which
is the athlete-facing grade. The migration copies each existing score into `distance_score`, since
every score written before it was distance-only.

**The chip keeps its four verdicts.** Only its meaning widened, and the Plan page's day chip carries
that meaning as its tooltip: done is both hit, partial is short on either, overreached is well past on
either, missed is no run.

**Regrading.** `plan:regrade-season` ([RegradeSeasonCommand](app/Console/Commands/Run/RegradeSeasonCommand.php))
re-runs the scorer over every past day of each athlete's current season and writes the verdict back
whichever way it moved. It is deterministic and calls no LLM, so a second run writes nothing.

## Consequences

- A tempo eased to easy and run easy is `hit`, so it grades on distance alone, as the eased session
  asked.
- The render-time fallback in `SessionMatcher::statuses()`, for a past row the daily pass has not
  reached yet, stays distance-only. The persisted verdict replaces it the next morning.
- A day graded before its runs carried streams or laps grades on distance, and a later regrade picks
  up whatever the data can now tell.
- Narration still describes the four statuses by distance. Teaching it the verdict's evidence is a
  separate change.

## Alternatives considered

**A new `off_intent` status.** Clearer on its own, but every surface, the adherence ring and the
narrators would need a fifth verdict, and the athlete already reads partial as "not quite the session".

**Let the widened score drive adaptation.** One figure everywhere, but an athlete who ran a week too
easy would be backed off as if they had skipped it, the opposite of what the week needs.
