---
title: Gamification (cards, rarities, badges, unlocks, milestones)
description: The reward engine — how a run becomes a card with rarity, badges and a special move, plus milestones, PRs and accessory unlocks.
tags: [feature, gamification]
status: living
reviewed: 2026-08-14
code_refs:
  - app/Services/Run/Story/RunCardFactory.php
  - app/Services/Run/Story/CardContext.php
  - app/Actions/Run/Story/BuildCardContextAction.php
  - app/Services/Run/Story/BadgeEvaluator.php
  - app/Services/Run/Story/RarityScorer.php
  - app/Services/Run/Story/SpecialMoves.php
  - app/Services/Run/Story/Temari.php
  - app/Actions/Gamification/DetectActivityMilestonesAction.php
  - app/Actions/Gamification/GrantEligibleUnlocksAction.php
  - app/Actions/Gamification/GrantSeasonUnlocksAction.php
  - app/Services/Gamification/GoalResolver.php
  - app/Services/Gamification/SeasonGoalResolver.php
  - app/Services/Gamification/SeasonGamificationContext.php
  - resources/js/components/trends/panels/FitnessTrend.tsx
  - app/Models/RunCard.php
  - app/Models/UserUnlock.php
  - app/Models/StreakRestToken.php
  - app/Actions/Gamification/SettleStreakRestTokensAction.php
  - app/Console/Commands/Gamification/SettleStreakTokensCommand.php
  - app/Models/PersonalRecord.php
---

# Gamification

Gamification isn't a page — it's an engine that runs as each activity is ingested. The visible payoffs (cards, rarities, records, unlock progress) surface across [[cards-collection]], [[records]] and [[targets-accessories]] (live accessory progress); badge milestones and PRs live on `/trends`. This note describes the engine and where each piece is wired.

**No dedicated route** — this is a service-layer engine, not a page.

## System dependencies

- **Ingestion** — `RunCardFactory` is invoked by the [[run-ingest-pipeline]] during activity ingest.
- **AI narration** — `Temari` writes `StoryLine` rows (mood, speech) that the [[ai-pipeline]] narrators reference.
- **Training metrics** — PRs are detected by `PersonalRecords` using data from [[stream-analysis]] and [[training-load-metrics]].
- **Data model** — `RunCard`, `UserUnlock`, `PersonalRecord` shapes in [[data-model]].

## A run becomes a card

[RunCardFactory](../../app/Services/Run/Story/RunCardFactory.php) (`build(Activity, ActivityDetail): RunCard`) is the entry point, but it only orchestrates and persists: it resolves the sticky PR flag, delegates the **badges** (weather, distance bracket, splits, streak) and the **rarity** score, names a **special move**, writes the row and queues the reveal. It is invoked from the ingest pipeline ([ActivityPipeline](../../app/Services/Run/Ingest/ActivityPipeline.php)).

The scoring rules themselves are pure. Everything that needs the user's whole history is resolved up front by [BuildCardContextAction::__invoke()](../../app/Actions/Run/Story/BuildCardContextAction.php#L38) into a [CardContext](../../app/Services/Run/Story/CardContext.php) (first run ever, first distance bracket, weekly consistency, day streak, athlete max HR); [BadgeEvaluator::evaluate()](../../app/Services/Run/Story/BadgeEvaluator.php#L62) and [RarityScorer::score()](../../app/Services/Run/Story/RarityScorer.php#L45) then read facts off that context and never touch the database. The builder folds the first-run, first-bracket and weekly-consistency counts into a single conditional aggregate, so a card costs two whole-history queries rather than four.

The rarity isn't a coin flip: [RarityScorer::score()](../../app/Services/Run/Story/RarityScorer.php#L45) folds a handful of run signals (distance, pace, weather, the earned badge set, PRs, and — since Slice 7 — executing a planned Tempo/Interval session at or faster than its prescribed pace) into a single number, and [fromScore()](../../app/Services/Run/Story/RarityScorer.php#L89) buckets that number into a tier. Tune the tier boundaries there, not in the callers. The same rarity rank is what the featured-kartu picker ranks on, see [[vibe-and-mood]].

**The quality-execution point (Slice 7) decouples rarity from distance.** Before it, a well-executed easy run could reach Rare (7-8) but never Epic/Legendary without distance or a PR — the non-distance/non-PR ceiling was 8. `BuildCardContextAction` now also looks up whether a [PlannedSession](../../app/Models/PlannedSession.php) (see [[plan-periodizer]]) existed for the run's date and was a quality session type; if the run's actual pace met or beat that session's prescribed [PaceBand](../../app/Enums/PaceBand.php), `CardContext::$qualitySessionPaceMet` is true and the score gets +2, raising that ceiling to 10 — a well-executed *quality* run now has its own path to the top tiers.

**The badge count's contribution is capped.** Badges stack with circumstance rather than merit — a hot, rainy, pre-dawn long run collects several without being remarkable — so an uncapped count dominated the score and made Rare the single most common tier, covering half of all cards. The ceiling keeps badges as one signal among several rather than the deciding one.

**The tier boundaries are fitted, not chosen.** The cap alone didn't settle it — as the corpus grew, Uncommon drifted into being the most common tier again. [run:compare-recalibration](../../app/Console/Commands/Run/CompareRecalibrationCommand.php) recomputes every stored run under the current rules and prints the score percentiles, and the boundaries are read straight off that table so Common is modal again. Re-run the command before moving them, and read the percentile table it prints rather than guessing.

Effort badges are read against the athlete's max HR, so a stale max quietly distorts them: `all_out` (hard) landed on 69% of runs while `easy_miles` (easy) fired on none at all, since its 70%-of-max bar describes a recovery jog rather than the easy run a Z2 session actually is. Both thresholds now sit where runners would recognise the effort, and max HR self-corrects during ingest (see [[stream-analysis]]).

The result persists to the `run_cards` table via [RunCard](../../app/Models/RunCard.php): `rarity` is a string column cast to the `Rarity` enum, `badges` casts to an array, and `special_move` holds the name. The model exposes `forUser()`, `badgeCountsForUser()` (lifetime, `Badge::tracked()` only — feeds `GamificationContext`), `allBadgeCountsForUser()` (every `Badge` case, optionally date-ranged) and `firstEarnedDatesForUser()` (the earliest date each badge slug was ever earned — feeds the badge-milestone timeline below) for the collection views.

**Two badges retired (Slice 7): `Berturut`/`streak` (7-day) and `Rajin`/`habit_forming` (3-day).** Both keyed off `CardContext::$consecutiveDaysBefore`, a daily-consecutive counter — a completely different signal from `GamificationContext::$streakWeeks`/`$twoWeekStreak` (weekly), which still drives `StreakRemindCommand` and the `aura_warmup` accessory goal untouched. `Badge` now has 16 cases (was 18 after Slice 2g retired the holiday badge). The frontend's `BADGE_LABELS`/`BADGE_ABILITY` drop the two entries too (same "let it fall back to `prettyBadge()`" treatment Slice 2g used for `holiday_run`), so a pre-existing card that still carries one of these slugs in its `badges` JSON array renders without crashing.

[SpecialMoves](../../app/Services/Run/Story/SpecialMoves.php) (`pick(...)`) deterministically chooses a thematic name (e.g. "Closing Kick", "Easy Miles", "Red Line") from buckets keyed on zone distribution and pace — same run, same name, every time.

[Temari](../../app/Services/Run/Story/Temari.php) wraps the mascot's reaction: it maps run metrics and the user's current vibe to a mood (blazing, easy, wobbly, gassed, overloaded, chill) and writes a `StoryLine`, so the card carries a voice, not just numbers.

## Milestones

[DetectActivityMilestonesAction](../../app/Actions/Gamification/DetectActivityMilestonesAction.php) fires the one-off celebration moments when an activity is newly ingested: first-ever distance bracket, first-ever pace, a PR, a new longest run. It is idempotent — guarded by a `milestones_detected_at` marker so re-ingesting the same activity never re-fires the confetti.

## Personal records

A PR is written by `app/Services/Run/Metrics/PersonalRecords` via `updateOrCreate` into the `personal_records` table — [PersonalRecord](../../app/Models/PersonalRecord.php) holds `category`, `value_sec` and `set_at`. Crucially, breaking any PR triggers the unlock engine in the same pass, so records and accessories stay in lockstep. See [[records]].

## Unlocks & accessories

[GrantEligibleUnlocksAction](../../app/Actions/Gamification/GrantEligibleUnlocksAction.php) (`__invoke(User): list<string>`) recomputes and persists which accessories a user has earned — medals, headband, shirt, shorts, shoes, aura. It is idempotent and is called after a PR is detected, after the weekly aggregation, and when a card reaches an elite rarity. Grants land in `user_unlocks` via [UserUnlock](../../app/Models/UserUnlock.php) (`unlock_key`, `unlocked_at`, `equipped`, `metadata`).

`config/temari_goals.php` is the single canonical catalog of grant criteria: each of the 25 keys declares a `metric`/`metric_key`/`target` triple against [GamificationContext](../../app/Services/Gamification/GamificationContext.php). [GoalResolver::currentValue()](../../app/Services/Gamification/GoalResolver.php#L87) resolves that triple to a current value for progress bars, and `GrantEligibleUnlocksAction` reuses the same method to decide grants generically (`current >= target`) instead of a hardcoded `if` per key — adding an unlock needs a config entry only, no PHP change. `config/temari_unlocks.php` stays display-only: name, icon, rarity, flavor description, keyed by the same unlock key.

[GoalResolver](../../app/Services/Gamification/GoalResolver.php) (`forUser()`, `completedCount()`, `closestToCompletion()`) computes progress toward *every* unlock in the catalog — current vs target — to feed the [[targets-accessories]] progress bars, including the ones not yet earned. **Only the page-level surface retired in Slice 7** (`GoalController`, `Goals.tsx`, the `goalsSummary` shared prop) — `GoalResolver` itself stays, since `GrantEligibleUnlocksAction` and the live progress numbers on `/accessories` both still depend on it.

## Season goals and the rest-day reward

A [Season](../../app/Models/Season.php) is the training arc `Season IS the training block` refers to — race-oriented (ends on `race_date`) or self-scaled (a fixed rolling 12-week block, matching `Periodizer::HORIZON_WEEKS`). [SeasonService::ensureCurrent()](../../app/Services/Run/Plan/SeasonService.php) auto-cycles it: called from both `Periodizer::regenerate()` and `PlanController::index()`, it closes the current season early and opens the other mode when a `RaceGoal` is set or cleared mid-season (mirroring `Periodizer`'s own "mode switch takes effect at the next call" rule), and rolls a self-scaled season into a fresh one once its 12 weeks expire — always without a gap or overlap. Every user gets a real season from their first Plan-tab view, before any plan has even been regenerated.

5 [SeasonGoal](../../app/Models/SeasonGoal.php) rows generate once, at season creation (a stable checklist, unlike the day-by-day plan): total sessions completed, total quality (Tempo/Interval) sessions completed, the season's single longest planned long run completed, rest days honored, and a 5th that's race-margin (race-oriented) or CTL-growth (self-scaled). [SeasonGoalResolver](../../app/Services/Gamification/SeasonGoalResolver.php) resolves `current` live from a [SeasonGamificationContext](../../app/Services/Gamification/SeasonGamificationContext.php) — the same `metric`-string-driven pattern `GoalResolver` uses, scoped to the season's date range instead of the user's whole history. Rendered on the Plan tab, see [[plan-periodizer]].

**The rest-day reward is not a `Badge`.** Every `Badge` grant requires a real ingested `Activity` (`run_cards.activity_id` is a required unique FK) — a rest day, by definition, has none. "Honored" = a `PlannedSession` with `session_type = Rest` where no `Activity` was logged that date (never a day with no `PlannedSession` row at all — that's simply unplanned, not honored). [GrantSeasonUnlocksAction](../../app/Actions/Gamification/GrantSeasonUnlocksAction.php) reuses `UserUnlock`'s shape instead, keyed `season.{id}.rest_honored_{3|7}` — the season id in the key is what makes the same threshold re-earnable every season, unlike the lifetime accessory catalog. No ingest hook can trigger it (honoring is an absence, not an arrival), so it's granted opportunistically wherever a `SeasonGamificationContext` is already computed: the Plan tab.

## The season track

The same per-season key namespace carries a **track**: one tier, `season.{id}.track_{N}`, per completed `SeasonGoal`, granted by the same [GrantSeasonUnlocksAction](../../app/Actions/Gamification/GrantSeasonUnlocksAction.php) on the same read paths.

It extends that namespace rather than paying out of the lifetime catalog **because the catalog has nothing left to give**: all 25 keys in `config/temari_unlocks.php` are claimed 1:1 by a criterion in `config/temari_goals.php`, and `GrantEligibleUnlocksAction` grants each once and never again — so a track drawing on it would pay a returning user nothing in their second season. The season id in the key is what makes a tier re-earnable, exactly as it is for the rest-day reward.

Goal targets are generated scaled to the season's own length ([SeasonService](../../app/Services/Run/Plan/SeasonService.php)), so a short race-oriented season and a 12-week self-scaled one both run a comparable track. Crossing a season boundary resets the track to zero and **revokes nothing** — the previous season's tiers are owned permanently, under their own season id. [SeasonRolloverTest](../../tests/Feature/Gamification/SeasonRolloverTest.php) pins both halves of that.

It surfaces as the Plan tab's season track rail, which also carries the reset honesty and a count of the tiers earlier seasons left behind — see [[plan-periodizer]].

## Rest tokens and the weekly streak

The weekly streak (`WeeklySnapshot::consecutiveWeekStreak()`) hard-resets to 0 as soon as one full week closes with no run. A **rest token** forgives exactly one such week, so a week lost to illness or a taper does not cost the streak.

- **Accrual** — one token every 4th streak week, matching the periodizer's own 3-build-1-deload cycle (`PhaseSchedule`), so a token lands as a deload week comes due. At most `SettleStreakRestTokensAction::MAX_HELD` are held at once, which is what stops a long streak banking enough weeks to make itself meaningless.
- **Spending is automatic**, at week close, and only when forgiving the week would actually bridge to a week the user ran — a token is never burned by a user with no streak to save. There is no surface on which a user could play one, and a token you have to remember would fail the runner it exists to protect.
- **A forgiven week bridges the streak without counting toward it.** The user did not run, so the number does not grow.
- Nothing is revoked when a streak breaks; the counter resets and the collection is untouched.

[SettleStreakTokensCommand](../../app/Console/Commands/Gamification/SettleStreakTokensCommand.php) (`streak:settle`, Monday 00:00) settles the closed week. It is scheduled **ahead of `ai:weekly-recap` (00:01)**, which reads the streak and would otherwise narrate one this command is about to restore; that ordering is asserted, not just commented.

Because `two_week_streak` is `min(streakWeeks, 2)`, a bridged streak can still reach the `aura_warmup` accessory goal. That is intended: the streak was preserved, so what the streak earns is preserved with it.

The streak, the open week's stake, and the held rest weeks render on Profile's season & streak panel — with no control to play a rest week, since there is nothing to play. The mobile-UX port's `plan/README.md` §5 ("Streak feature redesign") moved this off the Plan tab, consolidating it onto Trends' badge board instead; see [[plan-periodizer]] and [[profile]].

## Badge milestones

The standalone badge board (`/badges`) retired once its content moved onto `/trends`. [FitnessTrend](../../resources/js/components/trends/panels/FitnessTrend.tsx) plots each badge as a marker on the Fitness/Fatigue timeline, keyed off `RunCard::firstEarnedDatesForUser()` — first occurrence only, not a lifetime/season count. Markers within 22px of each other cluster into one with a count; a chip list below the chart is the precise control for reaching an individual badge. Name/emblem/criterion text still comes from the frontend's `runcard.ts` `BADGE_LABELS`/`BADGE_ABILITY` catalog, matching the old badge board. The rest-day reward isn't part of this timeline — it has no `Badge` case and no earned-activity date to plot against.

## See also

[[data-model]] · [[run-ingest-pipeline]] · [[cards-collection]] · [[records]] · [[targets-accessories]] · [[temari-mascot]] · [[vibe-and-mood]]
