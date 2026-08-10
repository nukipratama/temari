---
title: Vibe & mood system
description: The daily "vibe" that sets Temari's tone, the run-level mood vocabulary, and the featured-kartu pick that headlines the dashboard.
tags: [feature, story]
status: living
reviewed: 2026-08-03
code_refs:
  - app/Services/Run/Story/Vibe.php
  - app/Services/Run/Story/VibeMatrix.php
  - app/Services/Run/Story/Temari.php
  - app/Actions/Run/Story/ResolveFeaturedKartuAction.php
  - app/Services/Run/Story/PastYouMatcher.php
  - app/Services/Run/Story/BriefingComposer.php
  - resources/js/lib/temariPose.ts
  - app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php
---

# Vibe & mood system

Two distinct "feelings" drive how the app speaks. The **vibe** is a daily, *whole-runner* read derived from training-load signals — it colours the dashboard headline, picks [[temari-mascot]]'s pose, and steers the LLM's tone. The **mood** is a *per-run* reaction attached to a single activity's card. They share a vocabulary at the seams (the vibe maps onto a mood for the daily greeting) but answer different questions: "how are you doing lately?" vs "how did *that run* go?".

**No dedicated route** — this is a service-layer system that feeds [[dashboard]], [[run-detail]], and [[profile]].

## System dependencies

- **Training metrics** — the vibe is derived from `TrainingLoad::summary` (form, form_status) in [[training-load-metrics]].
- **Stream analysis** — run-level mood reads `stream_summary` from [[stream-analysis]].
- **Gamification** — mood for a run is written by `Temari` service during [[gamification]] ingest.
- **AI narration** — the vibe key is consumed as a tone signal by narrators in the [[ai-pipeline]].

## The daily vibe

[Vibe::current](../../app/Services/Run/Story/Vibe.php) gathers five signals for a user as-of a date — current `form` and `form_status` from [TrainingLoad::summary](../../app/Services/Run/Metrics/TrainingLoad.php), days since the last run, whether a [[records|PR]] landed recently, and the average HR/pace `decoupling` over a recent window. The PR lookback and decoupling lookback windows are constants on the class ([Vibe.php:57](../../app/Services/Run/Story/Vibe.php#L57)). It hands all five to a pure lookup table.

[VibeMatrix::pick](../../app/Services/Run/Story/VibeMatrix.php) is that table: an ordered cascade of guard clauses (first match wins, most-significant signal first — staleness, then a fresh PR, then the form-status bands, with decoupling as the tiebreaker between near-neighbours). It returns one of eight stable vibe keys. **Read the cascade at the source rather than this prose** — the thresholds live there and only there ([VibeMatrix.php:12](../../app/Services/Run/Story/VibeMatrix.php#L12)).

### The eight vibes

A fixed vocabulary — keys are internal, the labels + emoji are the display surface ([Vibe.php:17](../../app/Services/Run/Story/Vibe.php#L17)):

| Vibe (key) | Label | Emoji | Roughly means |
| --- | --- | --- | --- |
| `bouncy` | Bouncy | 🦘 | Aerobically sharp — pace held with HR *dropping* (negative decoupling) |
| `steady` | Steady | 🚶 | Nothing flagged; normal training rhythm |
| `worn_down` | Worn Down | 🥵 | `fatigued` form — tired but not over the edge |
| `cooked` | Cooked | 🍳 | `overreaching` without the drift flag — dug a hole |
| `fresh` | Fresh | 🌧️ | Rested and primed (`fresh` form) |
| `stretched_thin` | Stretched Thin | 🧵 | `overreaching` *with* high decoupling — running on fumes |
| `pumped` | Pumped | 💥 | A recent PR (and not already fatigued/overreaching) |
| `hibernating` | Hibernating | 🐻 | No run for a long stretch (or none ever) |

## Run-level moods

A finished run gets a single **mood** instead — six values on [Temari](../../app/Services/Run/Story/Temari.php): `blazing` (PR / hard win), `easy` (easy / negative split), `wobbly` (heat strain), `gassed` (decoupling drift), `overloaded` (hard-zone heavy / overreaching), `chill` (rest / default). The selection cascade is `moodForActivity` ([Temari.php:116](../../app/Services/Run/Story/Temari.php#L116)); it reads the run's [[stream-analysis|stream summary]] and weather. This mood is what [[gamification]] writes onto the run's `StoryLine`, and each mood also carries a 4-char "sigil" and an optional accessory hint for the SVG renderer ([Temari.php:31](../../app/Services/Run/Story/Temari.php#L31)).

The bridge between the two systems is `moodForVibe` ([Temari.php:148](../../app/Services/Run/Story/Temari.php#L148)): when there's no run to react to, the daily greeting still needs a mascot mood, so each vibe collapses onto the nearest run-mood.

## How the vibe is consumed

- **Dashboard pose.** The vibe key indexes `VIBE_TO_POSE` ([temariPose.ts:14](../../resources/js/lib/temariPose.ts#L14)) to choose Temari's body pose on [[dashboard|Hari Ini]] (e.g. `pumped` → pumped, `cooked`/`worn_down`/`stretched_thin` → wobble, `hibernating` → reading). This is a separate map from the run-level `MOOD_TO_POSE` in the same file.
- **Headline subtitle.** The label flows into the italic subtitle "kamu lagi {label}." and the eyebrow date line — see [[dashboard]] for the page wiring.
- **LLM tone.** [BriefingComposer::compose](../../app/Services/Run/Story/BriefingComposer.php) resolves the vibe once and hangs the briefing off it. The vibe *key* is then a context field the narrators key their tone to: the mascot voice keys its register to the vibe band ([BriefingMascotVoiceNarrator.php](../../app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php)) — energetic for `pumped`/`fresh`/`bouncy`, gentle for `worn_down`/`cooked`, coaxing for `hibernating`. The pipeline itself is documented in [[ai-pipeline]].

## Featured kartu

[ResolveFeaturedKartuAction::__invoke](../../app/Actions/Run/Story/ResolveFeaturedKartuAction.php) picks the one card the dashboard hero shows ("Kartu dari Temari"): scan the last few runs (window constant at [ResolveFeaturedKartuAction.php:21](../../app/Actions/Run/Story/ResolveFeaturedKartuAction.php#L21)), keep the **highest [[cards-collection|rarity]]**, break ties toward the **most recent** run. Because the resolver is the single source of truth, the rendered card and its "Kata Temari" quote can never describe different cards — the briefing keys the quote's analysis row off the *card id*, not the day ([BriefingComposer.php:42](../../app/Services/Run/Story/BriefingComposer.php#L42)), so a fresh run sliding the pick re-fetches the matching voice. The client mirrors the same tie rule in `featuredCardFor`; see [[dashboard]].

## Past-you matcher

[PastYouMatcher::findMatch](../../app/Services/Run/Story/PastYouMatcher.php) is a sibling story tool, not part of the vibe path: given a current run, it finds an *older* baseline run that's comparable enough to say "you've changed". It matches on pace-band, distance, and temperature within tolerances and a minimum age gap (all constants at the top of the class, [PastYouMatcher.php:22](../../app/Services/Run/Story/PastYouMatcher.php#L22)), preferring the *oldest* qualifying run, then reports the pace/time/HR deltas. The pace-band edges are in `paceBand` ([PastYouMatcher.php:156](../../app/Services/Run/Story/PastYouMatcher.php#L156)).

## See also

[[temari-mascot]] · [[gamification]] · [[dashboard]] · [[cards-collection]] · [[records]] · [[recaps]] · [[training-load-metrics]] · [[stream-analysis]] · [[ai-pipeline]]
</content>
</invoke>
