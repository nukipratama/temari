---
title: Personal records
description: Personal Bests, the always-full-history panel on /trends — every distance PR and best-effort-by-time PR.
tags: [feature, records]
status: living
reviewed: 2026-08-18
code_refs:
  - resources/js/components/trends/panels/PersonalBests.tsx
  - app/Http/Controllers/TrendsController.php
  - app/Models/PersonalRecord.php
  - app/Enums/PrCategory.php
---

# Personal records

Personal bests live as a panel on `/trends` (see [TrendsController](../../app/Http/Controllers/TrendsController.php)), not their own page — retired from the standalone `/records` scoreboard it used to be. `/rekor` (the Indonesian-named legacy redirect) now points at `/trends`; visiting the old `/records`/`/badges` routes themselves 404s, no redirect, since neither had external consumers.

**Navigation:** `TrendsController` ships `distanceRecords`/`paceRecords` alongside every other Trends panel; no route of its own.

## System dependencies

- **Gamification** — PRs are detected during ingest by [[gamification]]; this panel reads the `personal_records` table.

## What the controller assembles

`TrendsController::distanceRecords()`/`paceRecords()` walk `PrCategory::distances()`/`PrCategory::efforts()` in their declared (ascending) order, looking up each category's current `PersonalRecord` and skipping any category the user hasn't set yet — no `list<>` returned, `array<int,...>` since a fresh user's sparse categories mean the array isn't reliably contiguous by category. PR categories cover **1K / 5K / 10K / 15K / Half / Full Marathon** (distance) plus five best-effort time windows (pace); both split server-side now, not client-side.

## The panel (`trends/panels/PersonalBests.tsx`)

[PersonalBests](../../resources/js/components/trends/panels/PersonalBests.tsx) is deliberately simpler than the retired scoreboard — no featured-PR hero, no AI context line, no splits/weather/location, no milestone-strip "sub-X" goal delta (none of that data survived the move, see below). Two sections:

- **By distance** — a tile grid (`StatTile`, `tone="sunken"`), one per set category: label, duration, pace/km, and the date it was set.
- **Best effort by time** — a flat divided list of the pace-window PRs: label, pace/km, date.

When the user has no PRs at all in either section, the panel shows a one-line prompt instead.

## Notes

- **Real capability loss, not just a redesign**: the retired `RecordsController`/`PrScoreboardBuilder`/`Collection/Records.tsx` computed a featured PR's splits, weather, location, and a "you're N seconds off sub-X" milestone delta (via `MilestoneStrip`) and a `PrContext` AI context line. None of that ported — Personal Bests only shows category/value/date. Re-adding any of it is new scope, not a citation fix.
- PR detection happens during run ingest, not here — this panel reads the `personal_records` table. See [[data-model]] for the schema and [[gamification]] for how milestones and goals are derived.
