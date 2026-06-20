---
title: Personal records (Rekor)
description: The PR gallery — featured scoreboard, milestone strip, trophy wall, pace ticker, AI context line, and per-distance progression chart.
tags: [feature, records]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Koleksi/Rekor.tsx
  - app/Http/Controllers/RekorController.php
  - resources/js/components/koleksi/MilestoneStrip.tsx
  - resources/js/components/koleksi/ProgressionChart.tsx
  - resources/js/components/card/PrCard.tsx
  - resources/js/components/run/SplitsSparkline.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
---

# Personal records (Rekor)

`/rekor` is the runner's PR wall: best time at every distance, the standout effort blown up into a scoreboard, and a "you used to be slower" progression chart per distance.

## What the controller assembles

The single-action [RekorController](../../app/Http/Controllers/RekorController.php) loads the user's `PersonalRecord` rows (with just the activity-detail columns it needs), attaches each row's `PrContext` AI analysis, and computes three extras via injected services:

- `featuredExtras` — the standout PR's splits, weather, location, and goal delta, built by `PrScoreboardBuilder`.
- `progressionByCategory` — a weekly-best time series for each of 5K / 10K / HM / FM (the `PROGRESSION_CATEGORIES` constant), built by `ProgressionSeriesBuilder`. A distance with too few in-window runs is omitted.

PR categories cover **1K / 5K / 10K / 15K / Half / Full Marathon** plus pace best-efforts; the page splits them into distance PRs and pace PRs client-side.

## The page (`Koleksi/Rekor.tsx`)

[KoleksiRekor](../../resources/js/pages/Koleksi/Rekor.tsx) sorts distance PRs longest-first, picks the longest as the headline `featured`, and stacks:

- **HeroScoreboard** — an oversized time on a sky panel, the glowing Temari mascot, and the PR's **context line** streamed through [AnalysisStatus](../../resources/js/components/temari/AnalysisStatus.tsx) (see [[ai-pipeline]]). Captions (Tipe / Hari / Tempat / Cuaca) sit below, then a [SplitsSparkline](../../resources/js/components/run/SplitsSparkline.tsx) of the per-km pace.
- **MilestoneStrip** — only when the featured PR has a positive gap to its next round-number goal. [MilestoneStrip](../../resources/js/components/koleksi/MilestoneStrip.tsx) renders "you're N seconds off sub-X" using `targetSec` / `deltaSec` from `featuredExtras`.
- **TrophyWall** — every distance PR as a [PrCard](../../resources/js/components/card/PrCard.tsx) medallion (category, time, date, link to the run).
- **PaceTicker** — pace best-efforts on a dark scoreboard strip.
- **ProgressionSection** — a distance selector (5K / 10K / HM / FM, longest-last so the default lands on the headline distance) driving a [ProgressionChart](../../resources/js/components/koleksi/ProgressionChart.tsx). The copy frames it as "from {worst} to {best}, you cut {delta} in N weeks," with a goal chip when a sub-X target exists.

When the user has no PRs at all, the page shows an empty state instead of the scoreboard.

## Notes

- PR detection happens during run ingest, not here — this page reads the `personal_records` table. See [[data-model]] for the schema and [[gamification]] for how milestones and goals are derived.
- The context line is the only LLM-backed surface on this page; everything else is pure data. A failed analysis shows the per-block retry state from `AnalysisStatus`.
