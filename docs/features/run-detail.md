---
title: Run detail (single activity)
description: One run, fully unpacked — a headline hero, a "Past You" match, the story + adaptive claims voice card, the Q&A panel, and the breakdown (vitals, splits, laps)
tags: [feature, runs]
status: living
reviewed: 2026-10-09
code_refs:
  - resources/js/pages/Runs/Show.tsx
  - app/Http/Controllers/RunController.php
  - resources/js/components/run/RunHero.tsx
  - resources/js/components/run/PrBibStamp.tsx
  - app/Services/Run/Metrics/PrBibResolver.php
  - app/Models/RecordStamp.php
  - app/Http/Controllers/Api/RecordStampController.php
  - resources/js/components/run/EffortScore.tsx
  - resources/js/components/run/PastYouCard.tsx
  - resources/js/components/run/AskAboutRun.tsx
  - resources/js/hooks/useRunQuestions.ts
  - resources/js/components/run/RunLenses.tsx
  - resources/js/components/run/MapWeatherPanel.tsx
  - resources/js/components/run/VitalsCard.tsx
  - resources/js/components/run/SplitsChart.tsx
  - resources/js/components/run/LapsCarousel.tsx
  - resources/js/components/run/RouteMap.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
  - resources/js/components/MobileTopBar.tsx
  - resources/js/lib/nav.ts
  - resources/js/lib/navigationMemory.ts
---

# Run detail (single activity)

`/activities/{activity}` is the deep view of one run. [Show.tsx](../../resources/js/pages/Runs/Show.tsx)
(default export `RunsShow`) renders it from props assembled by
`RunController::show` in [RunController.php](../../app/Http/Controllers/RunController.php),
which 404s on a foreign or not-yet-analyzed activity and lazily kicks a
location-resolve job when the run has GPS but no resolved place name.
`activity` and `detail` are allowlists of the fields the page reads
(`ACTIVITY_FIELDS`, `DETAIL_FIELDS`), not serialized models, so start
coordinates, Strava's raw laps and splits arrays and the other unread columns
never reach the browser; the laps and splits views render from `stream_summary`.

**Navigation:** `route('activities.show', activity)` → `/activities/{activity}`.
Named route: `activities.show`. It is a **pushed** screen (P6): back chevron,
no bottom nav. An in-app visit returns to its recorded internal route and scroll
position; a cold deep link or notification launch falls back to History.

## Section order

`PS8` rebuilt the page to the prototype's section list (P28), and that order is
the spec — a slice that adds a section adds it here too:

1. the effort-score picker ([EffortScore](../../resources/js/components/run/EffortScore.tsx)), while the run has no score or the athlete is changing it
2. the `ACTIVITY` eyebrow
3. [RunHydratingNotice](../../resources/js/components/run/RunHydratingNotice.tsx), when the deeper fetch is still in flight
4. [RunHero](../../resources/js/components/run/RunHero.tsx) — identity, the headline stat block, and the route + conditions slab
5. [PastYouCard](../../resources/js/components/run/PastYouCard.tsx), when there is a match
6. [RunLenses](../../resources/js/components/run/RunLenses.tsx) — "what Temari says"
7. [AskAboutRun](../../resources/js/components/run/AskAboutRun.tsx)
8. the `THE BREAKDOWN` eyebrow
9. [VitalsCard](../../resources/js/components/run/VitalsCard.tsx)
10. [SplitsChart](../../resources/js/components/run/SplitsChart.tsx)
11. [LapsCarousel](../../resources/js/components/run/LapsCarousel.tsx)
12. the Strava provenance footer

**Sections 5-11 are gated on the run being detailed.** While `awaitingDetail` is
true the page renders the effort-score picker, the notice, the hero and the footer only — everything
below the hero reads from splits, zones and effort that have not landed, so it
would be a column of empty panels rather than a thin page.

## System dependencies

- **AI narration** — the story + adaptive-claims halves and the share image's caption are all `Analysis` rows from the [[ai-pipeline]]; the narrators read Past You through a tool, but the match itself is computed, not narrated. See [[past-you-engine]].
- **Ingestion** — `detail` / `stream_summary` are populated by the [[run-ingest-pipeline]].
- **Geo** — location name is resolved by [[geo-reverse-geocoding]].
- **Weather** — conditions come from [[weather-integration]].
- **Gamification** — the card rarity/badges are assigned by [[gamification]] during ingest.

## Hero — identity, one headline stat, route + weather

[RunHero](../../resources/js/components/run/RunHero.tsx) is a card-toned panel,
not a fixed-dark sky panel: the prototype draws this screen's hero on the
card surface, so it reacts to the ground like every other panel on the page.
It opens with Temari as the panel's watermark, posed to the run's mood, then the as-recorded date and time, the run name in serif
italic (a headline, so it keeps Fraunces) and the mood pill under it.

The stat block is a **hierarchy, not a grid of six equals**. Distance is the one
big mono figure; duration and pace sit beside it at supporting size; HR, TRIMP
and elevation are three small tiles below. Every figure count-ups from zero via
`useCountUp` and renders `—` where the run recorded nothing.

**PR bib stamp.** A run that currently holds one of the tracked records (the
5K, 10K, half or full marathon best, or the account's longest run) shows a
race-bib badge such as `10K · PR · 58:41` under the mood pill, on every view;
when it holds more than one, the rarer record wins.
[PrBibResolver](../../app/Services/Run/Metrics/PrBibResolver.php) builds the
`prBib` prop read-only, with `animate` true while no
[RecordStamp](../../app/Models/RecordStamp.php) row exists for that athlete and
record. Only then does [PrBibStamp](../../resources/js/components/run/PrBibStamp.tsx)
play its punch-in (static under `prefers-reduced-motion`) and a short vibrate
where the browser supports one, then claim the stamp with a fire-and-forget
`POST /api/record-stamps`
([RecordStampController](../../app/Http/Controllers/Api/RecordStampController.php)),
which first checks the athlete holds that record. The claim is keyed on the
record, not the run, so the animation plays once per record per account, on
whichever device opens it first, and a later run that takes the same record
shows the badge statically. Claiming on the POST rather than during the render
keeps the page's GET free of side effects, which its ETag replay
([SetInertiaEtag](../../app/Http/Middleware/SetInertiaEtag.php)) relies on.

The hero also carries the page's one **Share** button, rendered only when the
run has a card — the prototype draws no share button anywhere, and keeping one
is the deliberate divergence recorded in `cut-list.md` §4; see
[[cards-collection]].

At the foot of the panel,
[MapWeatherPanel](../../resources/js/components/run/MapWeatherPanel.tsx) is one
sunken slab: the **route map** first, the run's conditions (temperature /
humidity / wind / location) read underneath it. The map is the only heavyweight
child: [RouteMap](../../resources/js/components/run/RouteMap.tsx) is
`lazy()`-loaded and decodes `detail.summary_polyline`, so a treadmill run with
no polyline simply omits it and the slab collapses to the conditions row (and
renders nothing at all when the run has neither). The map starts behind a
tap-to-activate overlay button — Leaflet's drag handler otherwise captures a
touch-scroll swipe as a pan gesture, trapping the page mid-scroll on mobile;
one tap dismisses the overlay and enables full drag/zoom, the same pattern
Google Maps embeds use. The prototype fills this slot with a decorative
"activate map" placeholder; P16 keeps the real map there, which already carries
an activate pill of its own.

## Effort score

Any run, of any age and with or without heart rate, can take an effort score. Unscored, an [EffortPicker](../../resources/js/components/run/EffortScore.tsx) sits at the top of the page, above the hero, as a panel on the stat-tile ground in light and the sky panel in dark: a "gut check" eyebrow and the voice line "forget the watch. how'd that one feel?" beside a 44px [TemariMascot](../../resources/js/components/temari/TemariMascot.tsx) whose pose follows the slider ([effortPose](../../resources/js/lib/perceivedEffort.ts)), then the score row with save and the 1–10 slider. Saved, it collapses to a chip beside the mood chip in [RunHero](../../resources/js/components/run/RunHero.tsx) ("7/10 · very hard" in the band's ink on its tint) with "change", which reopens the picker, and "clear". The score is read from `detail.perceived_effort` and feeds no load; see [[an-effort-score-is-collected-on-every-run]].

## You vs past you

[PastYouCard](../../resources/js/components/run/PastYouCard.tsx) is its own card
directly under the hero — the prototype places the comparison in the page
column, not inside the hero. It leads with the pace delta, then the HR delta and
what that pace gap was worth over the whole distance, and links to the matched
run. `RunController::show` calls a `PastYouMatcher` and passes the `pastYou`
match; `findMatch` deliberately picks the **oldest** qualifying run so the
contrast reads as progress, while the home screen's trend verdict runs off the
same matcher but picks the most *comparable* run instead. Both selections, and
the verdict's outcomes, are [[past-you-engine]]. No match means no card at all,
not an empty state.

## What Temari says — story + adaptive claims

The heart of the page is [RunLenses](../../resources/js/components/run/RunLenses.tsx):
a heading tagged with a 40px Temari (posed to the run's mood, thinking while either lens is written) over **one** narration card, whose two halves are separated
by a hairline rather than split into two panels. It is fed two `Analysis`
payloads the controller resolves from `RunController::RUN_INSIGHT_TYPES`:

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

Each half renders through [AnalysisStatus](../../resources/js/components/temari/AnalysisStatus.tsx),
which owns the pending / processing / failed / done states and the per-block
"Try again" retry. These are **chained** analyses: only the chain head (the
user's latest run, `isChainHead` from `Activity::latestIdForUser`) shows the
single **Reread** control, a pill at the foot of the card that counts down the
shared cooldown; historical runs are resume-only. A half's label (and the
hairline above it) is drawn only when its block will render something, decided by
the same `rendersNothing` predicate `AnalysisStatus` uses: a plain pending block is
empty, queued, processing and failed blocks keep their label, and when both halves
are empty the whole section is not drawn. See [[ai-pipeline]] for the
narrator/job model behind these rows.

## Ask about this run

[AskAboutRun](../../resources/js/components/run/AskAboutRun.tsx) sits directly
under the narration card on the same voice surface: Temari says her piece, then
the reader gets to interrogate it. It renders the persisted thread **first**,
then the run's own unasked suggestions, then the ask box — the prototype's
order, so what was already answered reads before the invitation to ask again.

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

## The breakdown — vitals, splits, laps

[VitalsCard](../../resources/js/components/run/VitalsCard.tsx) opens it: average
heart rate as a headline figure with the max marked on a 100-190 scale bar, then
cadence / steepest grade / flat pace as three tiles, then decoupling as a marker
on a leaf→citrus→ember gradient with a plain-language line under it. Grade and
flat pace appear only on a run that actually climbed (≥3%), so a flat GPS run
does not show a noisy 0%; a corrupt JSON reading never renders as `NaN`. High
drift is toned as a warning **unless** the run was at or above
`WeatherSnapshot::HOT_RUN_TEMP_C` (31°C), the one hot-run threshold the backend
also uses and `typescript:enums` generates into the frontend, which mirrors the
backend's own rule — heat explains an upward drift, and cannot explain a
negative one. Relative effort is **not** here: P18 cut it, and the prototype's
vitals card draws these five readings instead.

[SplitsChart](../../resources/js/components/run/SplitsChart.tsx) reads
`stream_summary.per_km` as a bar chart — taller bar, faster km — with heart rate
traced over it as a dashed polyline (drawn only when at least two kms recorded
one) and a tap-to-read tooltip that dims the other bars. The trailing sub-km
remainder is appended as a dashed, unranked bar. Bar heights reuse
`computeBarWidth` from [lib/splits.ts](../../resources/js/lib/splits.ts) rather
than a raw min→max stretch, so a run whose kms are seconds apart still reads as
consistent instead of as a dramatic swing.

[LapsCarousel](../../resources/js/components/run/LapsCarousel.tsx) closes it:
the watch's own laps as side-scrolling cards, the fastest picked out. Native
overflow scroll, no paging buttons.

The page then closes with a static provenance footer — "Synced from Strava",
the sync instant, and the run's own `strava_external_id`.

## Related components, not wired here

Weather and Past You each have a standalone sibling component — `WeatherHero`
and `PastYouStrip` — used by other screens. **This page does not use them**: it
composes its own `MapWeatherPanel` and `PastYouCard`; treat the siblings as
separate widgets, not parts of this page.

## See also

- [[run-ingest-pipeline]] — how `detail` / `stream_summary` get populated
- [[data-model]] — `Activity`, `ActivityDetail`, `Analysis`, `StoryLine`
- [[ai-pipeline]] — the run-detail narration pipeline
- [[cards-collection]] — how the run's card is earned, and the share button that is now the only way to see it
