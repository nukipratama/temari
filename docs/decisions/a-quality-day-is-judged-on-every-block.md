---
title: A quality day is judged on every block and rep
description: Tempo blocks and stream-found reps are measured one by one, off autosplit-merged laps or the raw stream, and a multi-block tempo is read the way reps are.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-15
code_refs:
  - app/Services/Run/Metrics/WorkBlocks.php
  - app/Services/Run/Plan/SessionIntentJudge.php
  - app/Services/Run/Plan/ComplianceScorer.php
  - app/Services/Run/Plan/IntentOutcome.php
---

# A quality day is judged on every block and rep

**Status:** Accepted (2026-10-15). Amends the tempo and rep-window rules of [[a-day-is-graded-on-distance-and-intent]] and [[grading-follows-shown-advice-and-actual-stimulus]]; issue [#2149](https://github.com/nukipratama/temari/issues/2149).

## Context

[SessionIntentJudge](app/Services/Run/Plan/SessionIntentJudge.php) read a tempo from one stored best-effort window: the longest of the fixed windows (30 s up to 60 min) that fit inside the longest single block, taken from anywhere in the run. A 15-minute block read the 10-minute window, and on a 2 × 10 min tempo pace proved one block while heart-rate minutes stood in for the other. Laps couldn't help: a watch's 1 km autosplit cuts each block in two, and `IntervalDetector::detect()` rejects back-to-back work laps. In prod, a 2 × 10 min tempo at 6:03/km, run as prescribed with lap presses at each boundary, was judged on a single 10-minute stretch at 5:31/km; its blocks actually ran 5:29 and 5:50.

## Decision

**Each block is measured on its own.** [WorkBlocks::fromLaps()](app/Services/Run/Metrics/WorkBlocks.php) classes a lap as work when it ran quicker than halfway between the slowest block pace and easy pace, merges consecutive work laps into one block, and is trusted only when the merged blocks match the prescription one for one and each covers 90% of its minutes or its km (a block run by distance can finish early). The plain kilometre grid is never read. Failing that, [WorkBlocks::fromStream()](app/Services/Run/Metrics/WorkBlocks.php) finds the quickest non-overlapping stretches of each block's exact length in the raw stream. Only when neither works does the old best-window reading stand.

**Blocks are read the way reps are** (`SessionIntentJudge::blocksReading()`): all but one block within the target + 10 s, or one block when only one or two were asked, is a hit; the day is too hard when the blocks' time-weighted pace beats the target by more than 5%; short of the count, heart rate decides as before. The same rule covers the hard block of a fast-finish or marathon-pace long run, whose easy running is still held to the cap.

**Reps without laps are found the same way** (`SessionIntentJudge::repsFromStream()`): one stretch per prescribed rep, counted and averaged like lap reps. A stretch slower than halfway between rep and easy pace wasn't run as a rep and stays out of the average.

**Evidence** adds `block_paces`, `block_targets`, `block_pace_sec` (time-weighted), `blocks_at_pace`, `blocks_prescribed` and `block_source` (`laps` or `stream`) on a tempo, and `rep_pace_sec`, the average of every detected rep, on intervals. [ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php) loads the day's raw streams (`ComplianceScorer::streamsOf()`) for any day that isn't easy both as planned and as shown. [IntentOutcome](app/Services/Run/Plan/IntentOutcome.php) names the block behind a too-hard multi-block verdict ("the first block ran well past the effort it asked for; the second held near target") and quotes each block beside their average.

## Consequences

- A stored grade changes only when it is regraded: `plan:regrade-season` after deploy.
- The stream search costs one stream read per graded quality day, and sorts every candidate start per block, which is fine at 1 Hz on runs of a few hours.
- Laps whose classification is ambiguous (a cool-down lap near the halfway pace) fall through to the stream rather than being guessed.
