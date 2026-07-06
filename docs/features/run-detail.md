---
title: Run detail (single activity)
description: One run, fully unpacked — hero stats, route+weather, four AI lenses, splits, and a "Past You" match
tags: [feature, runs]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Runs/Show.tsx
  - app/Http/Controllers/RunController.php
  - resources/js/components/run/FourLensGrid.tsx
  - resources/js/components/run/RouteMap.tsx
  - resources/js/components/card/Kartu.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
---

# Run detail (single activity)

`/aktivitas/{activity}` is the deep view of one run. [Show.tsx](../../resources/js/pages/Runs/Show.tsx)
(default export `RunsShow`) renders it from props assembled by
`RunController::show` in [RunController.php](../../app/Http/Controllers/RunController.php),
which 404s on a foreign or not-yet-analyzed activity and lazily kicks a
location-resolve job when the run has GPS but no resolved place name.

## Hero — stats + route + weather

The top section is a sky-toned `HeroPanel`: the mascot in a mood-derived pose
(`MOOD_TO_POSE[mood]`), the run name, date, and five `StatTile`s
(jarak / durasi / pace / HR / TRIMP). The **"Past You"** comparison is inlined
right here in the hero — `RunController::show` calls a `PastYouMatcher` and
passes a `pastYou` match (pace + HR delta vs a similar run N days ago) with a
link to that older run.

To the right, `MapWeatherPanel` (a local component in `Show.tsx`) shows
temperature / humidity / location and the **route map**. The map is the only
heavyweight child: [RouteMap](../../resources/js/components/run/RouteMap.tsx) is
`lazy()`-loaded and decodes `detail.summary_polyline`, so a treadmill run with
no polyline simply omits it.

## Kata Temari — the four AI lenses

The heart of the page is [FourLensGrid](../../resources/js/components/run/FourLensGrid.tsx),
fed four separate `Analysis` payloads the controller resolves from
`RunController::RUN_INSIGHT_TYPES`:

- **Cerita lari ini** — the post-run speech (`PostRunSpeech`)
- **Terjemahan teknis** — `RunInsightTechnical`
- **Split paling seru** — `RunInsightSplits`
- **Zona HR** — `RunInsightZones`

Each lens renders through [AnalysisStatus](../../resources/js/components/temari/AnalysisStatus.tsx),
which owns the pending / processing / failed / done states and the per-block
"Coba lagi" retry. These are **chained** analyses: only the chain head (the
user's latest run, `isChainHead` from `Activity::latestIdForUser`) shows the
single "Baca ulang semua" regenerate button; historical runs are resume-only.
See [[ai-pipeline]] for the narrator/job model behind these rows.

## Kartu — the card's full view

When the run has a collectible [Kartu](../../resources/js/components/card/Kartu.tsx),
its own section sits right below the hero: the full-size card on a sky panel with
**Bagikan** (opens [ShareCardModal](../../resources/js/components/card/ShareCardModal.tsx))
and **Buka ulang kartu** (re-arms the pack-tear reveal), plus the lore column — the
streamed `CardFlavor` quote and a "Kenapa [rarity]" block explaining each badge.
`RunController::show` enriches the run's `RunCard` with that flavor analysis, its
edition (`index`/`total` within its rarity), and a signed `public_share_url`; there
is no separate card detail page, this section *is* it. See [[cards-collection]] for
the grid this card also appears in.

## Technical tiles & splits

Below the lenses, `DetailTiles` (local) surfaces AVG/MAX HR, cadence, ascent,
and decoupling (warned past 8%) — only the fields actually present render.
`SplitsTable` (local) reads `stream_summary.per_km` and draws a per-km pace bar
(fastest km highlighted), responsive between a mobile card stack and a desktop
grid.

## Related components, not wired here

The run-detail concerns of weather, HR zones, splits, and Past You each have a
standalone sibling component — `WeatherHero`, `HrZoneCard`, `PastYouStrip`,
`SplitsSparkline`. **This page does not use them**: `Show.tsx` re-implements
those concerns inline (`MapWeatherPanel`, `DetailTiles`, `SplitsTable`, the
hero Past You block). `HrZoneCard`/`SplitsSparkline` live on the collectible
card and records views instead; treat the siblings as separate widgets, not
parts of this page.

## See also

- [[run-ingest-pipeline]] — how `detail` / `stream_summary` get populated
- [[data-model]] — `Activity`, `ActivityDetail`, `Analysis`, `StoryLine`
- [[ai-pipeline]] — the four-lens narration pipeline
- [[cards-collection]] — the Kartu in the sidebar
