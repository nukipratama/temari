---
title: A session is the whole outing, and a cooldown is never prescribed
description: A day's distance_km covers everything it asks the athlete to run, warmup included, carved out of that budget rather than added on top of it.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-07
code_refs:
  - app/Services/Run/Plan/SegmentGenerator.php
  - app/Services/Run/Plan/SessionMatcher.php
  - app/Enums/SegmentKey.php
---

# A session is the whole outing, and a cooldown is never prescribed

**Status:** Accepted (2026-09-07)

## Context

Reported by the athlete reading their own plan. A Tempo day's card said **5.9 km**; following the card's own segment graph meant running **7.7 km**, because the 10-minute warmup and 5-minute cooldown sat outside the figure. [SessionMatcher](app/Services/Run/Plan/SessionMatcher.php) then graded the day's *total* logged distance against the *core* figure, so complying exactly scored **130% — `overreached`**. An Interval day was worse: 3.6 km prescribed, 7.7 km asked, **214%**.

This was not an oversight. [[plan-periodizer]] documented core-only deliberately, and the reason was real: warmup and cooldown are stored as fixed *minutes*, so expressing them in kilometres needs a VDOT estimate the athlete may not have yet, while `distance_km` had to stay pace-independent for [VolumeRedistributor](app/Services/Run/Plan/VolumeRedistributor.php) and for a brand-new account.

But core-only grading has no implementation. `SessionMatcher::completedKmByDate()` sums whatever was logged that day, and nothing can tell which kilometres of a run were the warmup. "Grade the core against the core" was never available — only "grade the whole run against the core", which is what produced the ratios above.

The split had spread further than the report. [PlanController::redistributeCurrentWeek()](app/Http/Controllers/PlanController.php) subtracted *total* completed km from a *core-only* week target, shrinking every day after a quality session. [SeasonSummaryBuilder](app/Services/Run/Plan/SeasonSummaryBuilder.php) and `WeekVolumeChart` plotted core `planned_km` against total `actual_km`. And the surfaces disagreed with each other in copy: Home's week widget said `planned 5.9k core`, Plan's day row said `5.9 km`.

## Decision

**A session is the whole outing.** `distance_km` is everything the day asks the athlete to run.

**The warmup is carved out of that budget, not added on top of it.** A Tempo day stays 5.9 km: roughly 1.2 km of easy warmup, then the balance at threshold. An Interval day's recovery jogs come out of the same budget, so its rep count solves `n·repKm + (n−1)·recoveryKm = budget` rather than appending recoveries to a separately-sized block.

**A cooldown is never prescribed.** Runners cool down on their own, the evidence for prescribing one is far weaker than for a warmup, and every kilometre it occupied was a kilometre of quality work displaced. `SegmentKey::Cooldown` is gone.

## Consequences

**`distance_km` does not change value.** `SegmentGenerator::coreKmFor()` still returns exactly what it returned before, and remains pace-independent. The prescription shrank to fit the number rather than the number growing to fit the prescription — so `planned_km_this_week`, `VolumeRedistributor`, `SeasonSummaryBuilder` and the week volume chart became correct with no edits, and **no persisted compliance score is invalidated**. Nothing is rescored; past verdicts stand.

**Quality blocks get shorter, which is a correction rather than a loss.** At the reporting athlete's paces a 5.9 km threshold block was ~40 minutes — in a Base phase whose own spec is *"at most one threshold session"*, and against a ~22 km weekly volume. It now lands near 32.

**The weekly volume anchor stays exact.** A four-session week's distances sum to `2.70 × long_run_km`, and `long_run_km` is `0.35 × weekly volume` below 30 km/week, so the week lands at ~0.945 of the athlete's own trimmed mean — see [[plan-volume-anchors-on-weekly-mean]]. Adding bookends on top would have pushed every athlete ~5% above the figure that ADR anchored on.

**With no VDOT estimate the day is unchanged.** Fixed minutes cannot become kilometres without a pace, so the warmup carves out nothing and the main block keeps the whole distance — exactly the behaviour that shipped before. Such an athlete has no meaningful compliance history to distort.

**A session too small to hold its own warmup still runs.** The bookend is a fixed duration while the day scales with the athlete, so at the [TrainingBaseline](app/Services/Run/Plan/TrainingBaseline.php) 3 km long-run floor in a reduced taper week a 12-minute warmup would exceed the whole day. `SegmentGenerator::MAX_WARMUP_SHARE` caps it at half the session, which is inert at every realistic size.

## Rejected

- **Dropping warmup and cooldown entirely.** Simplest model, and it makes `distance_km` the whole outing for free — but only fixes grading if the athlete genuinely stops warming up. Anyone doing threshold or VO2max work warms up regardless, logs the extra kilometres, and scores 130% again. It also means prescribing ~40 minutes at threshold from cold.
- **Adding the bookends on top instead.** Also honest, and preserves today's quality-block sizes exactly. Rejected because it raises every athlete's prescribed volume ~5% above the anchor [[plan-volume-anchors-on-weekly-mean]] had just established, and leaves the over-long threshold block in place.
- **A second `prescribed_total_km` field** for grading and sums, keeping `distance_km` as core for display. Rejected: two numbers to keep in sync, and every future consumer has to know which one it wants — the disagreement this note exists to end.
- **Keeping them as uncounted advice** in the day's note. Closest to the old behaviour, and reintroduces the original bug the moment the athlete follows the advice.
- **Rescoring persisted compliance.** Prescribed km is recomputed from *today's* baseline, so regrading an eight-week-old week judges it against fitness the athlete did not have then — a worse distortion than the one being fixed. Moot in the end, since the denominator did not move.
