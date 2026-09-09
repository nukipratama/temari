---
title: Gamification (cards, rarities, badges, milestones)
description: The reward engine — how a run becomes a card with rarity, badges and a special move, plus milestones, PRs, season goals and the weekly streak.
tags: [feature, gamification]
status: living
reviewed: 2026-09-09
code_refs:
  - app/Services/Run/Story/RunCardFactory.php
  - app/Services/Run/Story/CardContext.php
  - app/Actions/Run/Story/BuildCardContextAction.php
  - app/Services/Run/Story/BadgeEvaluator.php
  - app/Services/Run/Story/RarityScorer.php
  - app/Services/Run/Story/SpecialMoves.php
  - app/Services/Run/Story/Temari.php
  - app/Actions/Gamification/DetectActivityMilestonesAction.php
  - app/Services/Gamification/SeasonGoalResolver.php
  - app/Services/Gamification/SeasonGamificationContext.php
  - resources/js/components/trends/panels/FitnessPanel.tsx
  - app/Models/RunCard.php
  - app/Models/StreakRestToken.php
  - app/Actions/Gamification/SettleStreakRestTokensAction.php
  - app/Console/Commands/Gamification/SettleStreakTokensCommand.php
  - app/Models/PersonalRecord.php
---

# Gamification

Gamification isn't a page — it's an engine that runs as each activity is ingested. The visible payoffs (cards, rarities, records) surface across [[cards-collection]] and [[records]]; badges surface as chips on `/trends`' fitness panel, PRs as [[profile]]'s progression charts. This note describes the engine and where each piece is wired.

**No dedicated route** — this is a service-layer engine, not a page.

## System dependencies

- **Ingestion** — `RunCardFactory` is invoked by the [[run-ingest-pipeline]] during activity ingest.
- **AI narration** — `Temari` writes `StoryLine` rows (mood, speech) that the [[ai-pipeline]] narrators reference.
- **Training metrics** — PRs are detected by `PersonalRecords` using data from [[stream-analysis]] and [[training-load-metrics]].
- **Data model** — `RunCard`, `PersonalRecord` shapes in [[data-model]].

## A run becomes a card

[RunCardFactory](../../app/Services/Run/Story/RunCardFactory.php) (`build(Activity, ActivityDetail): RunCard`) is the entry point, but it only orchestrates and persists: it resolves the sticky PR flag, delegates the **badges** (weather, distance bracket, splits, streak) and the **rarity** score, names a **special move**, writes the row and queues the reveal. It is invoked from the ingest pipeline ([ActivityPipeline](../../app/Services/Run/Ingest/ActivityPipeline.php)).

The scoring rules themselves are pure. Everything that needs the user's whole history is resolved up front by [BuildCardContextAction::__invoke()](../../app/Actions/Run/Story/BuildCardContextAction.php#L59) into a [CardContext](../../app/Services/Run/Story/CardContext.php) (first run ever, first distance bracket, weekly consistency, day streak, athlete max HR); [BadgeEvaluator::evaluate()](../../app/Services/Run/Story/BadgeEvaluator.php#L42) and [RarityScorer::score()](../../app/Services/Run/Story/RarityScorer.php#L45) then read facts off that context and never touch the database. The builder folds the first-run, first-bracket and weekly-consistency counts into a single conditional aggregate, so a card costs two whole-history queries rather than four.

The rarity isn't a coin flip: [RarityScorer::score()](../../app/Services/Run/Story/RarityScorer.php#L45) folds a handful of run signals (distance, pace, weather, the earned badge set, PRs, and — since Slice 7 — executing a planned Tempo/Interval session at or faster than its prescribed pace) into a single number, and [fromScore()](../../app/Services/Run/Story/RarityScorer.php#L89) buckets that number into a tier. Tune the tier boundaries there, not in the callers.

**The quality-execution point (Slice 7) decouples rarity from distance.** Before it, a well-executed easy run could reach Rare (7-8) but never Epic/Legendary without distance or a PR — the non-distance/non-PR ceiling was 8. `BuildCardContextAction` now also looks up whether a [PlannedSession](../../app/Models/PlannedSession.php) (see [[plan-periodizer]]) existed for the run's date and was a quality session type; if the run's actual pace met or beat that session's prescribed [PaceBand](../../app/Enums/PaceBand.php), `CardContext::$qualitySessionPaceMet` is true and the score gets +2, raising that ceiling to 10 — a well-executed *quality* run now has its own path to the top tiers.

**The badge count's contribution is capped.** Badges stack with circumstance rather than merit — a hot, rainy, pre-dawn long run collects several without being remarkable — so an uncapped count dominated the score and made Rare the single most common tier, covering half of all cards. The ceiling keeps badges as one signal among several rather than the deciding one.

**The tier boundaries are fitted, not chosen.** The cap alone didn't settle it — as the corpus grew, Uncommon drifted into being the most common tier again. [run:compare-recalibration](../../app/Console/Commands/Run/CompareRecalibrationCommand.php) recomputes every stored run under the current rules and prints the score percentiles, and the boundaries are read straight off that table so Common is modal again. Re-run the command before moving them, and read the percentile table it prints rather than guessing.

Effort badges are read against the athlete's max HR, so a stale max quietly distorts them: `all_out` (hard) landed on 69% of runs while `easy_miles` (easy) fired on none at all, since its 70%-of-max bar describes a recovery jog rather than the easy run a Z2 session actually is. Both thresholds now sit where runners would recognise the effort, and max HR self-corrects during ingest (see [[stream-analysis]]).

The result persists to the `run_cards` table via [RunCard](../../app/Models/RunCard.php): `rarity` is a string column cast to the `Rarity` enum, `badges` casts to an array, and `special_move` holds the name. The model exposes `forUser()`, `allBadgeCountsForUser()` (every `Badge` case, optionally date-ranged) and `firstEarnedBadgesForUser()` (the earliest date each badge slug was ever earned, with that card's rarity — feeds the badge chips below) for the collection views.

**Two badges retired (Slice 7): `Berturut`/`streak` (7-day) and `Rajin`/`habit_forming` (3-day).** Both keyed off `CardContext::$consecutiveDaysBefore`, a daily-consecutive counter — a completely different signal from the weekly streak (`WeeklySnapshot::consecutiveWeekStreak()`), which still drives `StreakRemindCommand` untouched. `Badge` now has 16 cases (was 18 after Slice 2g retired the holiday badge). The frontend's `BADGE_LABELS`/`BADGE_ABILITY` drop the two entries too (same "let it fall back to `prettyBadge()`" treatment Slice 2g used for `holiday_run`), so a pre-existing card that still carries one of these slugs in its `badges` JSON array renders without crashing.

[SpecialMoves](../../app/Services/Run/Story/SpecialMoves.php) (`pick(...)`) deterministically chooses a thematic name (e.g. "Closing Kick", "Easy Miles", "Red Line") from buckets keyed on zone distribution and pace — same run, same name, every time.

[Temari](../../app/Services/Run/Story/Temari.php) wraps the mascot's reaction: it maps run metrics and the user's current vibe to a mood (blazing, easy, wobbly, gassed, overloaded, chill) and writes a `StoryLine`, so the card carries a voice, not just numbers.

## Milestones

[DetectActivityMilestonesAction](../../app/Actions/Gamification/DetectActivityMilestonesAction.php) fires the one-off celebration moments when an activity is newly ingested: first-ever distance bracket, first-ever pace, a PR, a new longest run. It is idempotent — guarded by a `milestones_detected_at` marker so re-ingesting the same activity never re-fires the confetti.

## Personal records

A PR is written by `app/Services/Run/Metrics/PersonalRecords` via `updateOrCreate` into the `personal_records` table — [PersonalRecord](../../app/Models/PersonalRecord.php) holds `category`, `value_sec` and `set_at`. See [[records]].

## Accessory unlocks (removed)

The 25-key accessory catalog is **gone**, and with it the `user_unlocks` table,
both grant actions, `GoalResolver`, `GamificationContext`, the two config
catalogs, the `unlock` inbox kind and `UnlockGrantedNotification`. Its last page
went with `PP2`; what remained afterwards was an engine granting rows that
nothing read, plus one inbox kind and a narration fact about a shelf the user
could not see. The two season-scoped rewards that borrowed `UserUnlock`'s row
shape — the rest-day tiers and the season track — went with it, since `PP3` had
already cut the rail that drew them. Git history holds the catalog.

## Season goals and the rest-day reward

A [Season](../../app/Models/Season.php) is the training arc `Season IS the training block` refers to — race-oriented (ends on `race_date`) or self-scaled (a fixed rolling 12-week block, matching `Periodizer::HORIZON_WEEKS`). [SeasonService::ensureCurrent()](../../app/Services/Run/Plan/SeasonService.php) auto-cycles it: called from both `Periodizer::regenerate()` and `PlanController::index()`, it closes the current season early and opens the other mode when a `RaceGoal` is set or cleared mid-season (mirroring `Periodizer`'s own "mode switch takes effect at the next call" rule), and rolls a self-scaled season into a fresh one once its 12 weeks expire — always without a gap or overlap. Every user gets a real season from their first Plan-tab view, before any plan has even been regenerated.

5 [SeasonGoal](../../app/Models/SeasonGoal.php) rows generate once, at season creation (a stable checklist, unlike the day-by-day plan): total sessions completed, total quality (Tempo/Interval) sessions completed, the season's single longest planned long run completed, rest days honored, and a 5th that's race-margin (race-oriented) or CTL-growth (self-scaled). [SeasonGoalResolver](../../app/Services/Gamification/SeasonGoalResolver.php) resolves `current` live by reading each goal's `metric` string against a [SeasonGamificationContext](../../app/Services/Gamification/SeasonGamificationContext.php), scoped to the season's date range. Rendered on the Plan tab, see [[plan-periodizer]].

**Rest days honored is a goal, not a `Badge`.** Every `Badge` grant requires a real ingested `Activity` (`run_cards.activity_id` is a required unique FK) — a rest day, by definition, has none. "Honored" = a `PlannedSession` with `session_type = Rest` where no `Activity` was logged that date (never a day with no `PlannedSession` row at all — that's simply unplanned, not honored). It counts as one of the five `SeasonGoal` rows instead, under the `season_rest_honored` metric, resolved live like the other four rather than granted by any hook — which suits a signal that is an absence rather than an arrival.

Goal targets are generated scaled to the season's own length ([SeasonService](../../app/Services/Run/Plan/SeasonService.php)), so a short race-oriented season and a 12-week self-scaled one both track comparably. Crossing a season boundary opens a fresh set of five and **revokes nothing** — a closed season keeps its own rows. [SeasonRolloverTest](../../tests/Feature/Gamification/SeasonRolloverTest.php) pins that.

## Rest tokens and the weekly streak

The weekly streak (`WeeklySnapshot::consecutiveWeekStreak()`) hard-resets to 0 as soon as one full week closes with no run. A **rest token** forgives exactly one such week, so a week lost to illness or a taper does not cost the streak.

- **Accrual** — one token every 4th streak week, matching the periodizer's own 3-build-1-deload cycle (`PhaseSchedule`), so a token lands as a deload week comes due. At most `SettleStreakRestTokensAction::MAX_HELD` are held at once, which is what stops a long streak banking enough weeks to make itself meaningless.
- **Spending is automatic**, at week close, and only when forgiving the week would actually bridge to a week the user ran — a token is never burned by a user with no streak to save. There is no surface on which a user could play one, and a token you have to remember would fail the runner it exists to protect.
- **A forgiven week bridges the streak without counting toward it.** The user did not run, so the number does not grow.
- Nothing is revoked when a streak breaks; the counter resets and the collection is untouched.

[SettleStreakTokensCommand](../../app/Console/Commands/Gamification/SettleStreakTokensCommand.php) (`streak:settle`, Monday 00:00) settles the closed week. It is scheduled **ahead of `ai:weekly-recap` (00:01)**, which reads the streak and would otherwise narrate one this command is about to restore; that ordering is asserted, not just commented.

The streak, the open week's stake, and the held rest weeks render on Profile's season & streak panel — with no control to play a rest week, since there is nothing to play. The mobile-UX port's `plan/README.md` §5 ("Streak feature redesign") moved this off the Plan tab; the prototype-parity program's P25/P27 then cut Trends' badge board too, so the week streak survives on Trends as a single chip inside [FitnessPanel](../../resources/js/components/trends/panels/FitnessPanel.tsx). See [[plan-periodizer]] and [[profile]].

## Badge milestones

The standalone badge board (`/badges`) retired once its content moved onto `/trends`, and decision P14 of the prototype-parity program then narrowed badges to exactly two surfaces: Trends' fitness-panel chips and Inbox's unlock rows (the latter gone with the unlock system, leaving one). [FitnessPanel](../../resources/js/components/trends/panels/FitnessPanel.tsx) draws one chip per badge earned inside the selected range window, keyed off `RunCard::firstEarnedBadgesForUser()` — first occurrence only, not a lifetime/season count, and carrying the rarity of the card that earned it so the chip's medal can be tinted. Every one of them renders, wrapping (P15); tapping a chip expands its criterion text. Name/criterion text still comes from the frontend's `runcard.ts` `BADGE_LABELS`/`BADGE_ABILITY` catalog, matching the old badge board. Rest days honored aren't part of this list — that's a `SeasonGoal`, with no `Badge` case and no earned-activity date.

## See also

[[data-model]] · [[run-ingest-pipeline]] · [[cards-collection]] · [[records]] · [[temari-mascot]] · [[vibe-and-mood]]
