---
title: Gamification (cards, rarities, badges, unlocks, milestones)
description: The reward engine — how a run becomes a card with rarity, badges and a special move, plus milestones, PRs and accessory unlocks.
tags: [feature, gamification]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/Run/Story/RunCardFactory.php
  - app/Services/Run/Story/SpecialMoves.php
  - app/Services/Run/Story/Temari.php
  - app/Services/Gamification/MilestoneDetector.php
  - app/Services/Gamification/UnlockEngine.php
  - app/Services/Gamification/GoalResolver.php
  - app/Models/RunCard.php
  - app/Models/UserUnlock.php
  - app/Models/PersonalRecord.php
---

# Gamification

Gamification isn't a page — it's an engine that runs as each activity is ingested. The visible payoffs (cards, rarities, records, unlock progress) surface across [[cards-collection]], [[records]] and [[targets-accessories]]. This note describes the engine and where each piece is wired.

**No dedicated route** — this is a service-layer engine, not a page.

## System dependencies

- **Ingestion** — `RunCardFactory` is invoked by the [[run-ingest-pipeline]] during activity ingest; `GenerateRunCardJob` handles async card generation.
- **AI narration** — `Temari` writes `StoryLine` rows (mood, speech) that the [[ai-pipeline]] narrators reference.
- **Training metrics** — PRs are detected by `PersonalRecords` using data from [[stream-analysis]] and [[training-load-metrics]].
- **Data model** — `RunCard`, `UserUnlock`, `PersonalRecord` shapes in [[data-model]].

## A run becomes a card

[RunCardFactory](../../app/Services/Run/Story/RunCardFactory.php) (`build(Activity, ActivityDetail): RunCard`) is the centrepiece. It scores the run, derives a **rarity** from that score, attaches a list of **badges** (weather, distance bracket, splits, streak), and names a **special move**. It is invoked from the ingest pipeline (`app/Services/Run/Ingest/ActivityPipeline.php`) and from `app/Jobs/Story/GenerateRunCardJob.php`.

The rarity isn't a coin flip: [rarityScore](../../app/Services/Run/Story/RunCardFactory.php#L155) folds a handful of run signals (distance, pace, weather, the earned badge set, PRs) into a single number, and [rarityFromScore](../../app/Services/Run/Story/RunCardFactory.php#L199) buckets that number into a tier. Tune the tier boundaries there, not in the callers. The same rarity rank is what the featured-kartu picker ranks on, see [[vibe-and-mood]].

**The badge count's contribution is capped.** Badges stack with circumstance rather than merit — a hot, rainy, pre-dawn long run collects several without being remarkable — so an uncapped count dominated the score and made Langka the single most common tier, covering half of all cards. The ceiling keeps badges as one signal among several rather than the deciding one.

**The tier boundaries are fitted, not chosen.** The cap alone didn't settle it — Langka was still the most common tier on real data. [run:compare-recalibration](../../app/Console/Commands/Run/CompareRecalibrationCommand.php) recomputes every stored run under the current rules and prints the score distribution, and the boundaries are the closest integers that put Langka-or-better near a fifth of cards rather than a third. Because scores are integers the fit is coarse: the next boundary up would collapse that share to under 8%. Re-run the command before moving them, and read the percentile table it prints rather than guessing.

Effort badges are read against the athlete's max HR, so a stale max quietly distorts them: `keras` (hard) landed on 69% of runs while `santai` (easy) fired on none at all, since its 70%-of-max bar describes a recovery jog rather than the easy run a Z2 session actually is. Both thresholds now sit where runners would recognise the effort, and max HR self-corrects during ingest (see [[stream-analysis]]).

The result persists to the `run_cards` table via [RunCard](../../app/Models/RunCard.php): `rarity` is a string column cast to the `Rarity` enum, `badges` casts to an array, and `special_move` holds the name. The model exposes `forUser()` and `badgeCountsForUser()` for the collection views.

[SpecialMoves](../../app/Services/Run/Story/SpecialMoves.php) (`pick(...)`) deterministically chooses a thematic name (e.g. "Closing Kick", "Easy Miles", "Red Line") from buckets keyed on zone distribution and pace — same run, same name, every time.

[Temari](../../app/Services/Run/Story/Temari.php) wraps the mascot's reaction: it maps run metrics and the user's current vibe to a mood (nyala, enteng, oleng, lemes, mumet, adem) and writes a `StoryLine`, so the card carries a voice, not just numbers.

## Milestones

[MilestoneDetector](../../app/Services/Gamification/MilestoneDetector.php) (`detect(...)`) fires the one-off celebration moments when an activity is newly ingested: first-ever distance bracket, first-ever pace, a PR, a new longest run. It is idempotent — guarded by a `milestones_detected_at` marker so re-ingesting the same activity never re-fires the confetti.

## Personal records

A PR is written by `app/Services/Run/Metrics/PersonalRecords` via `updateOrCreate` into the `personal_records` table — [PersonalRecord](../../app/Models/PersonalRecord.php) holds `category`, `value_sec` and `set_at`. Crucially, breaking any PR triggers the unlock engine in the same pass, so records and accessories stay in lockstep. See [[records]].

## Unlocks & accessories

[UnlockEngine](../../app/Services/Gamification/UnlockEngine.php) (`grantEligible(User): list<string>`) recomputes and persists which accessories a user has earned — medals, ikat_kepala, kaus, celana, sepatu, aura — from PR counts, rarity/badge collection, distance milestones and streaks. It is idempotent and is called after a PR is detected, after the weekly aggregation, and when a card reaches an elite rarity. Grants land in `user_unlocks` via [UserUnlock](../../app/Models/UserUnlock.php) (`unlock_key`, `unlocked_at`, `equipped`, `metadata`).

[GoalResolver](../../app/Services/Gamification/GoalResolver.php) (`forUser()`, `completedCount()`, `closestToCompletion()`) computes progress toward *every* unlock in the catalog — current vs target — to feed the [[targets-accessories]] progress bars, including the ones not yet earned.

## See also

[[data-model]] · [[run-ingest-pipeline]] · [[cards-collection]] · [[records]] · [[targets-accessories]] · [[temari-mascot]] · [[vibe-and-mood]]
