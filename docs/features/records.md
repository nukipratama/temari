---
title: Personal records
description: The PR gallery — featured scoreboard, milestone strip, trophy wall, pace ticker, and the AI context line.
tags: [feature, records]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Collection/Records.tsx
  - app/Http/Controllers/RekorController.php
  - resources/js/components/collection/MilestoneStrip.tsx
  - resources/js/components/card/PrCard.tsx
  - resources/js/components/run/SplitsSparkline.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
---

# Personal records

`/records` is the runner's PR wall: best time at every distance and the standout effort blown up into a scoreboard. The per-distance progression chart lives on [[profile]], not here.

**Navigation:** `route('records')` → `/records`. Named route: `records`.

## System dependencies

- **Gamification** — PRs are detected during ingest by [[gamification]]; this page reads the `personal_records` table.
- **AI narration** — the PR context line is a `PrContext` analysis from the [[ai-pipeline]].

## What the controller assembles

The single-action [RekorController](../../app/Http/Controllers/RekorController.php) loads the user's `PersonalRecord` rows (with just the activity-detail columns it needs), attaches each row's `PrContext` AI analysis, and ships two props:

- `personalRecords` — each PR row plus its `context_analysis` payload.
- `featuredExtras` — the standout PR's splits, weather, location, and goal delta, built by `PrScoreboardBuilder` off `pickFeaturedPr`.

PR categories cover **1K / 5K / 10K / 15K / Half / Full Marathon** plus pace best-efforts; the page splits them into distance PRs and pace PRs client-side.

## The page (`Collection/Records.tsx`)

[Records](../../resources/js/pages/Collection/Records.tsx) sorts distance PRs longest-first, picks the longest as the headline `featured`, and stacks:

- **HeroScoreboard** — an oversized time on a sky panel, the glowing Temari mascot, and the PR's **context line** streamed through [AnalysisStatus](../../resources/js/components/temari/AnalysisStatus.tsx) (see [[ai-pipeline]]). Captions (Type / Date / Location / Weather) sit below, then a [SplitsSparkline](../../resources/js/components/run/SplitsSparkline.tsx) of the per-km pace.
- **MilestoneStrip** — only when the featured PR has a positive gap to its next round-number goal. [MilestoneStrip](../../resources/js/components/collection/MilestoneStrip.tsx) renders "you're N seconds off sub-X" using `targetSec` / `deltaSec` from `featuredExtras`.
- **TrophyWall** — every distance PR as a [PrCard](../../resources/js/components/card/PrCard.tsx) medallion (category, time, date, link to the run).
- **PaceTicker** — pace best-efforts on a dark scoreboard strip.

When the user has no PRs at all, the page shows an empty state instead of the scoreboard.

## Notes

- PR detection happens during run ingest, not here — this page reads the `personal_records` table. See [[data-model]] for the schema and [[gamification]] for how milestones and goals are derived.
- The context line is the only LLM-backed surface on this page; everything else is pure data. A failed analysis shows the per-block retry state from `AnalysisStatus`.
