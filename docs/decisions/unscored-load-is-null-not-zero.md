---
title: An unscored week is null, a rest week is zero
description: Training load carries a run-day set beside its TRIMP map so a week that ran without heart rate reports unknown, while a week nobody ran keeps its honest zero.
tags: [decision, run]
status: accepted
reviewed: 2026-08-14
code_refs:
  - app/Services/Run/Metrics/TrainingLoad.php
  - app/Services/Run/Metrics/WeeklyAggregator.php
  - resources/js/types/inertia.ts
  - resources/js/pages/Activities/useFeedFilters.ts
  - resources/js/components/dashboard/KondisiCard.tsx
---

# An unscored week is null, a rest week is zero

**Status:** Accepted (2026-08-14)

## Context

Edwards TRIMP needs heart-rate minutes per zone, so a run without an HR stream scores nothing. Under [[summary-first-ingest]] that is the *normal* state of a freshly connected athlete: their entire history arrives summary-only and carries no `trimp_edwards` at all.

[weekStats](app/Services/Run/Metrics/TrainingLoad.php#L250) only ever saw the daily TRIMP map, which by construction contains scored days and nothing else. Two very different weeks therefore arrived at it looking identical — an empty map slice — and both left as `0.0` for `weekly_trimp`, `monotony` and `strain`:

- a **rest week**, where nobody ran, and zero is the truth;
- an **unscored week**, where they ran and we simply have no reading.

`0.0` states the first. For a new athlete every week is the second, so the training-load picture opened on a flat zero line: a year of "you did nothing" told to someone who ran all of it. The `TrainingLoad` TypeScript shape was non-nullable on top of that, so no consumer could have expressed unknown even if the backend had sent it.

## Decision

**Carry the run days beside the TRIMP, and let the three week-window fields be null.**

[loadDailyHistory](app/Services/Run/Metrics/TrainingLoad.php#L110) returns both maps from one query (a day whose runs all lack TRIMP sums to SQL `NULL`, so it lands in `runDays` and not in `trimp`), and [dailyHistory](app/Services/Run/Metrics/WeeklyAggregator.php#L223) derives the same pair from the detail set it has already loaded. One source each, so the two maps cannot drift apart.

`weekStats` then answers three ways rather than two: no runs in the window is `0.0`, runs but nothing scored is `null`, anything scored is the number.

**A sentinel was rejected outright.** A magic number eventually gets averaged, summed or charted by a consumer that never learned it was magic — which is exactly how the `0.0` this decision removes came to mean "no data" in the first place.

### What each consumer does with unknown, and why they differ

- **`weekly_trimp` / `monotony` / `strain`** — nullable. Their window is exactly the seven days in question, so when it holds no reading there is nothing to report.
- **ATL / CTL / form / form_status** — stay numbers. These are EWMAs over roughly a year of real history ([rollDailySeries](app/Services/Run/Metrics/TrainingLoad.php#L220)), where an unscored day is treated as a missing one and understates the average rather than voiding it. Nulling a year-long average because of one gap would trade a small understatement for a total loss of signal, and any threshold at which "enough gaps" flips it to null would be arbitrary. The whole summary is already null when *nothing* in the lookback scored, which is the honest answer for a wholly-unscored athlete.
- **[Readiness](app/Services/Run/Metrics/Readiness.php)** — a null monotony applies no cap, which its `?float` contract already meant. Its guardrails only fire on evidence, and unknown is not evidence.
- **[PlanAdapter](app/Services/Run/Plan/PlanAdapter.php)** — same: `strainIsExcessive` and the monotony deload need a real number, so an unknown week triggers no adaptation rather than a false one.
- **[BriefingContext](app/Services/Run/Story/BriefingContext.php)** — falls back to the most recent snapshot's monotony when the live value is unknown. Previously the live `0.0` won and suppressed that fallback; a real recent reading is a better basis for a safety ceiling than nothing.
- **[WeekTotalsTool](app/Services/AI/Agent/Tools/WeekTotalsTool.php) / [WeeklyRecapNarrator](app/Services/AI/Narrators/WeeklyRecapNarrator.php)** — the tool passes nulls straight through, so both now say in words that a null is unknown load rather than zero load, and that an unknown week is never a coast.
- **[groupByWeek](resources/js/pages/Activities/useFeedFilters.ts)** — the frontend keeps its own `totalTrimp` accumulator for filtered views, which started at `0` and skipped unscored runs, reproducing the same lie the snapshot no longer tells. It now starts null and only becomes a number once something scores.

### What the UI says

A missing reading renders as the em-dash the rest of the app already uses for a null value, never as a zero: `— TRIMP` in the Jejak week header, `—` in the dashboard's weekly TRIMP tile and in Kondisi's Strain and Monotony rows. Kondisi additionally names the cause, since "no number" and "no sensor" are different messages to a new athlete: `no HR on these runs` per row, and `no HR data yet` in the card header when a week of runs scored nothing at all.

## Consequences

- **Enables:** a first screen that reads "we have no reading yet" instead of "you have been idle for a year", and consumers that can tell an unknown week from a rest week without guessing.
- **Costs:** every reader of the three fields must handle null. The TypeScript shape enforces this on the frontend; on the backend the `?float` contracts largely already did.
- **A partially-scored week is still an understatement**, not an unknown: one HR-bearing run among five reports that one run's load. It is low rather than absent, so it does not read as idleness, and splitting "partial" into a fourth state would need a policy on how partial is too partial.
- **Backfilled snapshots correct themselves.** Hydrating a run recomputes its week and every later one through [rebuildForwardFrom](app/Services/Run/Metrics/WeeklyAggregator.php#L68), so unknown becomes a number as the history fills in.

## See also

- [[summary-first-ingest]] — why an athlete's history is summary-only to begin with.
- [[training-load-metrics]] — the engine these fields come out of.
