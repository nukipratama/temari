---
title: Run detail (single activity)
description: One run, fully unpacked — hero stats, route+weather, the story + adaptive claims lenses, splits, and a "Past You" match
tags: [feature, runs]
status: living
reviewed: 2026-08-10
code_refs:
  - resources/js/pages/Runs/Show.tsx
  - app/Http/Controllers/RunController.php
  - resources/js/components/run/PastYouHero.tsx
  - resources/js/components/run/AskAboutRun.tsx
  - resources/js/hooks/useRunQuestions.ts
  - resources/js/components/run/RunLenses.tsx
  - resources/js/components/run/MapWeatherPanel.tsx
  - resources/js/components/run/DetailTiles.tsx
  - resources/js/components/run/SplitsTable.tsx
  - resources/js/components/run/RouteMap.tsx
  - resources/js/components/card/Kartu.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
---

# Run detail (single activity)

`/activities/{activity}` is the deep view of one run. [Show.tsx](../../resources/js/pages/Runs/Show.tsx)
(default export `RunsShow`) renders it from props assembled by
`RunController::show` in [RunController.php](../../app/Http/Controllers/RunController.php),
which 404s on a foreign or not-yet-analyzed activity and lazily kicks a
location-resolve job when the run has GPS but no resolved place name.

**Navigation:** `route('activities.show', activity)` → `/activities/{activity}`. Named route: `activities.show`.

## System dependencies

- **AI narration** — the story + adaptive-claims lenses and card flavor are all `Analysis` rows from the [[ai-pipeline]]; the narrators read Past You through a tool, but the match itself is computed, not narrated. See [[past-you-engine]].
- **Ingestion** — `detail` / `stream_summary` are populated by the [[run-ingest-pipeline]].
- **Geo** — location name is resolved by [[geo-reverse-geocoding]].
- **Weather** — conditions come from [[weather-integration]].
- **Gamification** — the kartu rarity/badges are assigned by [[gamification]] during ingest.

## Hero — stats + route + weather

The top section is a sky-toned `HeroPanel`: the mascot in a mood-derived pose
(`MOOD_TO_POSE[mood]`), the run name, date, and six `StatTile`s
(distance / duration / pace / HR / TRIMP / elevation).

Below that stat grid, spanning the **full hero width** rather than sitting
beside it, is
[PastYouHero](../../resources/js/components/run/PastYouHero.tsx) — the
"you vs past you" claim the product is built on, so on this page it is the
headline, not a footnote. It leads with the pace delta as the page's single
gradient number, then the HR delta and what that pace gap was worth over the
whole distance, and links to the matched run. `RunController::show` calls a
`PastYouMatcher` and passes the `pastYou` match; `findMatch` deliberately picks
the **oldest** qualifying run so the contrast reads as progress, while the home
screen's trend verdict runs off the same matcher but picks the most
*comparable* run instead. Both selections, and the verdict's outcomes, are
[[past-you-engine]]. No match means the band is absent entirely, not an empty
state.

To the right (below the stats on mobile, since the hero grid stacks under the
`lg` breakpoint), [MapWeatherPanel](../../resources/js/components/run/MapWeatherPanel.tsx) shows
temperature / humidity / location and the **route map**. The map is the only
heavyweight child: [RouteMap](../../resources/js/components/run/RouteMap.tsx) is
`lazy()`-loaded and decodes `detail.summary_polyline`, so a treadmill run with
no polyline simply omits it. The map starts behind a tap-to-activate overlay
button — Leaflet's drag handler otherwise captures a touch-scroll swipe as a
pan gesture, trapping the page mid-scroll on mobile; one tap dismisses the
overlay and enables full drag/zoom, the same pattern Google Maps embeds use.

## What Temari says — story + adaptive claims

The heart of the page is [RunLenses](../../resources/js/components/run/RunLenses.tsx),
fed two `Analysis` payloads the controller resolves from
`RunController::RUN_INSIGHT_TYPES`:

- **This run's story** — the post-run speech (`AnalysisType::PostRunSpeech`, [PostRunSpeechNarrator](../../app/Services/AI/Narrators/PostRunSpeechNarrator.php)). Unchanged by this consolidation: deliberately not a summary of the numbers, it carries the run's context and its place in the athlete's history, leaving pacing, cadence and zones to the block beside it.
- **What stood out** — the adaptive claims block (`AnalysisType::RunInsight`, [RunInsightNarrator](../../app/Services/AI/Narrators/RunInsightNarrator.php)). Replaces the previous fixed three-slot layout (technical translation / best split / HR zones) with a single row whose `content` is a JSON-encoded list of 1-3 claims, shaped by what was actually notable about the run rather than by three always-present sections. Each claim is `{anchor, text, value?, delta?}`.

**Falsifiability, not prompt trust.** Every claim's `anchor` names the exact
real thing it describes — `split:<n>` (a 1-indexed km from the run's own
splits), `zone:<z1..z5>` (an HR zone this run actually recorded), or
`metric:<name>` (one of `decoupling` / `hr_drift` / `cadence_drop` /
`pace_variability` / `grade` / `negative_split` / `gap_pace`). Before
persisting, `RunInsightNarrator` checks each claim's anchor against the run's
own `StreamSummary`/`ActivityDetail` and drops any claim that does not
resolve — an LLM cannot narrate a split, zone, or metric the run does not
actually have. If every claim is dropped (or the model returned none), the
row's content decodes to `[]` and the frontend renders nothing for this
block, the same "nothing to render" principle used elsewhere in the pipeline.
The demo-seed/no-Azure fallback ([RuleBasedRunInsights](../../app/Services/AI/RuleBased/RuleBasedRunInsights.php))
emits the same claims shape from deterministic thresholds, so it never needs
the falsifiability check to begin with.

Each lens renders through [AnalysisStatus](../../resources/js/components/temari/AnalysisStatus.tsx),
which owns the pending / processing / failed / done states and the per-block
"Coba lagi" retry. These are **chained** analyses: only the chain head (the
user's latest run, `isChainHead` from `Activity::latestIdForUser`) shows the
single "Reread all" regenerate button; historical runs are resume-only.
See [[ai-pipeline]] for the narrator/job model behind these rows.

## Ask about this run

Directly under the hero sits
[AskAboutRun](../../resources/js/components/run/AskAboutRun.tsx): the hero
states the numbers, this panel lets the reader interrogate them, so the two are
one composition rather than two stacked features. It renders the run's own
suggested questions, an ask box, and the persisted thread.

The panel talks to the JSON API directly, not to Inertia props — see
[[run-qa]] for the endpoints and [[scoped-run-qa-not-an-analysis-row]] for why
the rows live outside the `Analysis` model.
[useRunQuestions](../../resources/js/hooks/useRunQuestions.ts) owns the state:
it loads the thread on mount, polls while any answer is `queued`/`processing`
(a tool-calling answer can block ~90s), gives up after a bounded number of
polls into a "still working" state with a manual re-check rather than spinning
forever, and maps the rate-limit `429`, the paused-generation `409` and a
validation `422` each to their own honest line. A failed question is terminal
by design, so the UI offers to refill the box rather than faking a retry.

A run still in `summary` ingest state says so in the panel: the toolbox behind
the answer drops splits, zones and terrain on that run, so the panel names the
limit up front instead of quietly answering thinner.

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

Below the lenses, [DetailTiles](../../resources/js/components/run/DetailTiles.tsx)
surfaces AVG/MAX HR, cadence, ascent, and decoupling (warned past 8%) — only the
fields actually present render.
[SplitsTable](../../resources/js/components/run/SplitsTable.tsx) reads
`stream_summary.per_km` and draws a per-km pace bar (fastest km highlighted),
responsive between a mobile card stack and a desktop grid; its pace-parsing and
bar-width maths live in [lib/splits.ts](../../resources/js/lib/splits.ts).

## Related components, not wired here

The run-detail concerns of weather, HR zones, splits, and Past You each have a
standalone sibling component — `WeatherHero`, `HrZoneCard`, `PastYouStrip`,
`SplitsSparkline`. **This page does not use them**: the page composes its own
`MapWeatherPanel` / `DetailTiles` / `SplitsTable` (plus the hero Past You
block). `HrZoneCard`/`SplitsSparkline` live on the collectible card and records
views instead; treat the siblings as separate widgets, not parts of this page.

## See also

- [[run-ingest-pipeline]] — how `detail` / `stream_summary` get populated
- [[data-model]] — `Activity`, `ActivityDetail`, `Analysis`, `StoryLine`
- [[ai-pipeline]] — the run-detail narration pipeline
- [[cards-collection]] — the Kartu in the sidebar
