---
title: History narrates on demand
description: Pre-connect runs are hydrated but filled rule-based on ingest; an LLM read is requested by the athlete on the run's own page, and only once every older run in the backfill window has hydrated.
tags: [decision, ai, cost]
status: accepted
reviewed: 2026-09-15
code_refs:
  - app/Services/AI/HistoryNarrationGate.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Http/Controllers/Api/AnalysisController.php
  - app/Services/AI/BackfillAgeGate.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
  - app/Actions/AI/RecentlyActiveUsers.php
  - app/Actions/AI/RequestTodaysBriefing.php
  - app/Console/Commands/AI/DailyBriefingCommand.php
  - app/Console/Commands/AI/WeeklyProfileCommand.php
  - app/Services/AI/SelfHealer.php
  - app/Actions/AI/SettleEarlyNarrationAction.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Console/Commands/Strava/HydrateBacklogCommand.php
  - app/Jobs/AI/KickoffRecapsJob.php
  - app/Actions/AI/KickoffMonthlyRecaps.php
  - app/Services/AI/PlanNarrationRequester.php
  - app/Jobs/Strava/HydrateBacklogForUserJob.php
---

# History narrates on demand

**Status:** Accepted (documented 2026-09-15)

> **2026-09-18 — every narration waits for the history past-you reads (#1012).** The last-7-days
> carve-out below narrated on ingest while older runs were still summary-only, and the text was
> never revisited: a run's stored narration cited a comparison from 293 days back that the app no
> longer makes. `HistoryNarrationGate::awaitsOlderHydration()` now holds any ingested run, historical
> or live, whose older history within `PastYouMatcher::MAX_GAP_DAYS` (365) still awaits hydration.
> The run is staged `Pending` and `ai:self-heal` narrates it once that history lands. The hold is
> bounded by `ai.recap_hydration_grace_hours` after the connect, so for an athlete connected longer
> ago than that the gate does nothing. The on-demand gate below uses the same 365-day look-back in
> place of the 84-day window, and still has no time limit.

> **2026-09-18 — the last 7 days of history no longer wait for "Try again".** #989: a day-one
> backfill's automatic spend used to depend on how much history an athlete imported, since nothing
> here capped it. `HistoryNarrationGate::narratesAutomatically()` now carves out the last
> `RecentlyActiveUsers::ACTIVE_WINDOW_DAYS` (7) days of a historical run — the same window
> [[narration-spends-only-on-active-athletes]]'s `NarrateOnReturnJob` already applies to a returning
> athlete's pending runs — and narrates it on ingest like any other run. Everything older still
> takes this decision's on-demand path unchanged. This bounds a backfill's automatic cost to at most
> 7 days of runs regardless of import depth, deliberately, not as a degradation of the ceiling.

> **2026-09-18 — the drain this hold waits on now runs oldest-first (#1023).** "`strava:hydrate-backlog`
> drains newest-first, so the wait is bounded by the drain reaching the older end of the window" below
> no longer describes the drain's order — see [[chronological-hydration-drain]]. The hold's own logic is
> unchanged: a run still waits while any older run within `PastYouMatcher::MAX_GAP_DAYS` awaits
> hydration, bounded by the same grace window. Oldest-first only changes *when* that condition clears —
> now monotonically, as the drain works forward through the window, rather than depending on the drain
> reaching backward into it.

> **2026-09-19 — correction (#1044): the briefing narrator never calls `get_latest_past_you`.**
> The note below says the daily briefing's own narrator reads past-you's bounded reach through
> that tool; it doesn't — `BriefingMascotVoiceNarrator` calls `get_week_state` / `get_training_load`
> / `get_recent_runs`, none of them past-you. The bound was chosen for consistency with the per-run
> hold ({@see \App\Services\Run\Story\PastYouMatcher::MAX_GAP_DAYS}), not because the briefing reads
> that comparison itself. The gate and its grace-window bound are otherwise exactly as described.

> **2026-09-19 — the daily briefing and the weekly profile voice hold the same way (#1032).**
> `DispatchPostRunAnalysis` requested both unconditionally for every non-away athlete, so a first-day
> backfill's first briefing and first-week profile read were written against whatever sliver of
> history had landed at connect time. The briefing reuses `awaitsOlderHydration()` unchanged, anchored
> on now rather than the ingested run's own date, because its own narrator reads exactly past-you's
> bounded reach (`get_latest_past_you`). The profile voice needed a wider gate —
> `HistoryNarrationGate::awaitsFullHydration()` — because it reads the athlete's whole history
> (`get_lifetime_stats`, the full PR table, all-time plan adherence), so a run outside past-you's
> 365-day reach can still be exactly the one it misreads. Both holds are bounded by the same
> `ai.recap_hydration_grace_hours` window, so a long-connected athlete's timing is unchanged; `ai:self-heal`
> releases a held row once its history lands, same as the per-run hold above.

> **2026-09-19 — the connect/signup briefing paths and the two scheduled kickoffs hold too (#1032
> re-check).** The previous note only closed the ingest-time gap; `RequestTodaysBriefing::atSignup()`
> and `::afterBackfill()` still bypassed it, and so did the `ai:daily-briefing`/`ai:weekly-profile`
> 00:01/Monday kickoffs. `atSignup()` fires from the onboarding wizard before the backfill sync has
> written a single `Activity` row, so `awaitsOlderHydration()` would vacuously read "nothing awaiting
> hydration" — there is nothing yet for the row-based gate to find. `users.backfilled_at` (stamped by
> `KickoffRecapsJob` immediately before it calls `afterBackfill()`) closes that gap: a null
> `backfilled_at` holds outright, then the same bounded gate takes over for the remaining
> detail-hydration window `afterBackfill()` can still land inside. Both entry points stage `Pending`
> (`AnalysisService::requestDeferred()`) instead of generating while held, and the existing
> `SelfHealer::resumeSingleRowType()` releases the row — no second release mechanism. `afterBackfill()`'s
> once-per-day `Cache::add` guard, which exists only to stop a re-run connect chain from re-billing,
> now runs *after* the hold check, so it can never consume the day's one real request while the row is
> still held. `DailyBriefingCommand`/`WeeklyProfileCommand` gained the same two checks for a first
> connect late enough that its backlog drain crosses the kickoff — staging instead of generating, same
> release path. `PlanNarrationRequester::requestClampVoice()`/`requestDayVoiceIfChanged()` were checked
> too: both do read hydrated history (the clamp voice through `TrainingLoad`'s 365-day-converged
> ATL/CTL; the day voice through `TrainingBaseline`'s trailing-weeks average and `SessionMatcher`'s
> credited-km read), so by this decision's own reasoning they belong on the same hold. Left unfixed
> here: unlike `BriefingMascotVoice`/`ProfileVoice`, neither has a `SelfHealer` recovery family — each
> fires only from the ingest listener's `isToday` branch (the clamp also from the 00:01 kickoff, for
> a new day, never a retroactive re-check of a held one). Holding them with no release path would trade
> "narrated thin" for "never narrated, no route to get one" — worse, and a false-hope Pending skeleton
> with nothing behind it. Building that release path is a separate, larger change; tracked as a
> follow-up rather than folded in here.

> **2026-09-19 — the per-run, briefing and profile-voice holds above are lifted for a fresh
> connect's recent runs; a one-time replay closes the gap (#1054).** #1057 hydrates a fresh
> connect's last `RecentlyActiveUsers::ACTIVE_WINDOW_DAYS` days first (recent-first), then the
> rest oldest-first as before ([[hydrate-on-connect]]). `awaitsOlderHydration()` /
> `awaitsFullHydration()` are unchanged, but `DispatchPostRunAnalysis`, `RequestTodaysBriefing`,
> `DailyBriefingCommand` and `WeeklyProfileCommand` no longer stage a row Pending when they're
> true: the run, the day's briefing and the profile voice narrate right away, with two things
> withheld rather than prompted away — a prompt rule already proved unreliable here. PR
> detection (`PersonalRecords::detectAndStore()`) is skipped in `ActivityPipeline::ingest()` for
> a run still inside `awaitsOlderHydration()`'s reach, so no PR row, card badge or notification
> is ever minted off an incomplete past. `TrainingLoadTool`, `WeekStateTool`/`BriefingContext`,
> `LifetimeStatsTool` and `PersonaMixTool` return `history_loading: true` and withhold
> CTL/ATL/form/monotony/volume-trend instead of computing them from a partial history.
> `AnalysisService::markDone()` detects the same condition live (at generation time, not
> dispatch time) and stamps `Analysis::$narrated_early_at` plus `User::$history_replay_due_at`;
> it also skips the notification, since every channel's delivery claim is keyed on the row's id
> for good and would otherwise permanently spend it on a run that couldn't yet know it set a PR.
> `ActivityPipeline::ingest()` stamps the same user flag directly when it defers PR detection, so
> the replay still runs even if narration itself finishes after the drain (and so was never
> marked early at all).
> Once the drain empties, `SettleEarlyNarrationAction` (triggered from the same
> `ActivityPipeline::ingest()` that already runs the #1022 card replay) rebuilds PRs
> (`PersonalRecords::rebuildForUser()`), re-judges cards (`RecomputeCardClaimsAction`), and
> regenerates every marked row exactly once — its claim is one locked transaction that clears
> both markers, so a second drain-complete signal or a later `ai:self-heal` sweep claims nothing
> and bills nothing more. A long-connected athlete never satisfies `awaitsOlderHydration()` /
> `awaitsFullHydration()` in the first place, so none of this fires for them.
>
> Three surfaces are pure load/fitness reads with nothing safe to say thin, so they are held
> outright instead of narrated early: `KickoffRecapsJob::kickoffTrendReads()` skips the Trends
> (`TrendRead`) read while any backlog awaits hydration, and `KickoffMonthlyRecaps` stages a
> narratable month's `MonthlyRecap` Pending (never rule-based — it deserves the real thing) while
> its own runs are still hydrating, both stamping the same `history_replay_due_at` flag with no
> `Analysis` row ever created for the reader to mark. `SettleEarlyNarrationAction` asks for both
> again, unconditionally and idempotently, on every successful claim. `PlanDayVoice`
> (`PlanNarrationRequester::requestDayVoiceIfChanged()`) is different: it *is* narrated early, off
> a thin `TrainingBaseline`, because #939 already made a credited day's own read a genuine event
> worth reacting to same-day. `AnalysisService::markDone()` marks it the same way as the per-run
> types, but the replay leaves its row Done rather than pre-flipping it Pending: the day-voice
> row has no `SelfHealer` recovery family, and `requestDayVoiceIfChanged()`'s own material-
> fingerprint check decides for itself whether the now-real baseline actually moved the read
> — pre-flipping it would strand a row nothing re-fills when it decides nothing changed. The
> plan clamp voice needed no equivalent: `RestClampRecorder::record()` already refuses to record
> a clamp at all while `HydrationBacklog::recentLoadAwaitsScoring()` is true (#1059), so
> `requestClampVoice()` finds nothing to narrate and creates no row — see #1044.

## Context

A first Strava connect imports the athlete's history, and the ingest cascade narrated every run of
it that fell inside the 84-day backfill window: `CardFlavor`, `PostRunSpeech` and `RunInsight`, three
model calls per run. Measured, that is roughly 13k tokens per run and around 2.5M tokens for one
signup.

All three surfaces render on one page — the run's own detail page. Everything the rest of the app
structurally needs from a historical run (splits, TRIMP, PRs, zones, weekly aggregates, the plan's
volume anchor) is deterministic and free. So the backfill was paying, up front and for every run,
for prose that is read only if someone opens that specific run, which most historical runs never
are.

[[twelve-week-narration-cutoff]] already handles the far end of this: past 84 days there is no LLM
narration at all. This is the same argument applied one step nearer.

## Decision

A run that started before the athlete's Strava connection is *history* — it exists only because the
first-connect backfill imported it. `HistoryNarrationGate::isHistorical()` says so, anchored on
`strava_connections.created_at`, the same anchor [[recap-waits-for-hydration]] uses.

On ingest, a historical run takes the path a too-old run already takes: streams and metrics hydrate
exactly as before, and its three narration rows are filled by `RuleBasedNarrationFiller` and marked
`Done`. No LLM job is dispatched for it. Runs after the connect are untouched — the webhook cascade
narrates them automatically, oldest link first, exactly as today.

The LLM read of a historical run is the per-block **"Try again"** on that run's page:
`AnalysisController::trigger()` invalidates the rule-based row and narrates it properly. That is the
existing manual path, with its existing cooldown, rate limit, ceiling and chain resumption — nothing
new renders, and nothing new is billed without a click.

That trigger waits on hydration. `HistoryNarrationGate::awaitsHydration()` refuses it with **409**
while any *older* run inside the backfill window is still `Activity::awaitingHydration()`. The
per-run narrators read resolved metrics through `get_recent_runs` and `get_recent_baseline`, and
narration is requested `invalidate: false`, so a read taken over a half-hydrated history writes a
thin story that nothing ever re-narrates. `strava:hydrate-backlog` drains newest-first, so the wait
is bounded by the drain reaching the older end of the window.

## Consequences

- A fresh signup's history costs the backfill nothing in tokens. The steady-state per-run spend is
  unchanged.
- A historical run's page shows rule-based narration until the athlete asks for a real read. It is
  never empty, and the ask is one click on the control that already exists.
- The refusal is a 409, the same status the paused-generation gate returns, so the UI's existing
  error path covers it without a new affordance.
- The gate is per athlete and per run: a run with nothing older than it left to hydrate reads
  immediately, even mid-drain.
- The first run after the connect reads a rule-based `prev_narrative` for its chain continuity,
  because that is what the run before it now holds. One link, once per athlete.
- An athlete whose Strava connection row is gone has no anchor, so nothing of theirs is historical.
  Runs only exist because a connection ingested them, and revocation keeps the row.

## Alternatives considered

**Dispatch the LLM read when the page is opened, rather than on "Try again".** Rejected here: a page
open is not a request for a billed call, and a scroll through history would spend on every run
passed. The manual trigger is an explicit ask, already rate-limited and already understood.

**Narrate history lazily but eagerly re-narrate once hydration completes.** Rejected: that is the
2.5M tokens again, deferred by a few hours.

See [[twelve-week-narration-cutoff]] · [[recap-waits-for-hydration]] ·
[[background-hydration-drain]] · [[chained-narration]] · [[per-block-manual-retry]]
