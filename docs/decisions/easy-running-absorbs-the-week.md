---
title: Easy running absorbs the week, key sessions keep their size
description: A week running ahead or behind trims or tops up its easy days only; the long run, tempo and interval never resize, and what easy days cannot absorb is dropped.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-09-24
code_refs:
  - app/Services/Run/Plan/PlanPageAssembler.php
  - app/Services/Run/Plan/VolumeRedistributor.php
---

# Easy running absorbs the week, key sessions keep their size

## Context

The Plan page resizes the rest of the current week against what has already been run ([[plan-periodizer]]). It used one scale factor across every remaining training day, so a long run was cut or inflated exactly as hard as an easy jog. On a real week an overrun, compounded by a pinned day counted twice, took a 10.4 km long run to 7.3 km. The double count is fixed separately; the proportional cut would still have hit the long run on any honest overrun, and a shortfall would have crammed missed km into it (up to the 1.35 cap).

## Decision

Only easy days absorb the week's surplus or shortfall. [PlanPageAssembler::redistributeCurrentWeek()](app/Services/Run/Plan/PlanPageAssembler.php) sets aside the km of the long, tempo and interval days still ahead and hands [VolumeRedistributor](app/Services/Run/Plan/VolumeRedistributor.php) the easy days alone, within the existing 0.7–1.35 bounds. What the easy days cannot absorb is dropped. A quality day the engine already prescribes as easy counts as easy, matching what the page shows.

A run on a rest day still counts toward the week: load is load. It now only shortens the easy days, which is the coaching answer to an extra run anyway.

## Why

Standard coaching practice: the long run and the quality sessions are the week's stimulus and are sized on purpose; easy running is the flexible filler. Missed mileage is not chased, and extra easy mileage does not cost the athlete their key work.

## Consequences

- A long, tempo or interval day never shows a `trimmed` / `topped up` tag.
- A week with no easy days left carries its surplus or shortfall nowhere.
- Grading is unchanged: it never used the redistributed figure.
