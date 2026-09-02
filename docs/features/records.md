---
title: Personal records
description: Where every distance PR and best-effort-by-time PR lives now that the Personal Bests panel is cut — the personal_records table and Profile's progression charts.
tags: [feature, records]
status: living
reviewed: 2026-08-31
code_refs:
  - app/Http/Controllers/ProfileController.php
  - app/Models/PersonalRecord.php
  - app/Enums/PrCategory.php
  - resources/js/components/profile/ProgressionCard.tsx
  - resources/js/components/profile/JourneyChart.tsx
---

# Personal records

PRs are detected during ingest and stored in `personal_records`. They have had three homes: a
standalone `/records` scoreboard (retired), a **Personal Bests** panel on `/trends` (**cut in
`PP3`**, decision P25 — the prototype's Trends screen draws four blocks and a personal-bests table
is not one of them), and their surviving home, **[[profile]]'s progression charts**.

The old `/records` / `/badges` routes 404, no redirect, since neither had external consumers. The
Indonesian-named `/rekor` redirect that used to point at `/trends` was deleted in `C1` along with
every other legacy redirect.

## System dependencies

- **Gamification** — PRs are detected during ingest by [[gamification]]; nothing here writes them.

## Where they surface

[ProfileController](../../app/Http/Controllers/ProfileController.php) builds
`progressionByCategory` from `PersonalRecord` rows across 5K / 10K / Half / Marathon and renders it
through [ProgressionCard](../../resources/js/components/profile/ProgressionCard.tsx)'s
[JourneyChart](../../resources/js/components/profile/JourneyChart.tsx) — a
per-distance journey line rather than a scoreboard. The prototype draws exactly that
(`ProfileScreen.tsx`'s `ProgressionCard`), which is why this is the reading that survived the cut.

PR categories cover **1K / 5K / 10K / 15K / Half / Full Marathon** (distance) plus five
best-effort time windows (pace), declared in [PrCategory](../../app/Enums/PrCategory.php). The
profile charts only plot the four distances above; the pace windows are stored and currently drawn
nowhere.

## Notes

- **Real capability loss across both retirements, not just a redesign.** The `/records`
  scoreboard computed a featured PR's splits, weather, location, a "you're N seconds off sub-X"
  milestone delta and a `PrContext` AI line; the Trends panel that replaced it kept only
  category/value/date; the cut removed that too. Re-adding any of it is new scope.
- `TrendsController` no longer ships `distanceRecords` / `paceRecords`; `W2` decides whether the
  now-unread pace-window categories stay.
