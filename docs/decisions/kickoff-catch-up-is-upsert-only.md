---
title: The kickoff catch-up sweep only creates rows, it never fills them
description: An hourly upsert-only sweep recreates the Analysis rows a missed 00:01 or Monday scheduler minute never staged; self-heal still owns filling them.
tags: [decision, ai]
status: accepted
reviewed: 2026-09-09
code_refs:
  - app/Actions/AI/KickoffCatchUp.php
  - app/Actions/AI/RecentlyActiveUsers.php
  - app/Console/Commands/AI/CatchUpCommand.php
  - app/Services/AI/SelfHealer.php
  - routes/console.php
---

# The kickoff catch-up sweep only creates rows, it never fills them

**Status:** Accepted (documented 2026-09-09). Extends [[bounded-self-heal-and-dead-letter]], which
covers the fill side; nothing it decided changes.

## Context

Every recovery family in [SelfHealer](../../app/Services/AI/SelfHealer.php) starts from a row that
already exists — `resumeSingleRowType` [:291](../../app/Services/AI/SelfHealer.php#L291),
`resumeCardFlavor` [:253](../../app/Services/AI/SelfHealer.php#L253) and the two chain sweeps all
query `Analysis::query()->stalled()`. There is no `firstOrCreate` anywhere in it.

But a row's existence is decided by a single scheduled minute. `ai:daily-briefing` stages the day's
`BriefingMascotVoice` at 00:01, and the Monday block at 00:00–00:07 stages the week's recap and
profile voice. If the scheduler container is down across that minute, no row is ever created and
self-heal has nothing to find: a resting athlete silently loses that day's briefing, and a Monday
outage loses the week's recap and profile narration outright. The only partial mitigation was the
ingest cascade, which covers only an athlete who happened to run that day.

## Decision

We added a **separate hourly `ai:catch-up` command** ([CatchUpCommand](../../app/Console/Commands/AI/CatchUpCommand.php),
scheduled at [routes/console.php:115](../../routes/console.php#L115)) that **creates missing kickoff
rows and does nothing else**.

- **It runs the kickoffs' own creation code**, under
  [`AnalysisService::withoutDispatching()`](../../app/Services/AI/AnalysisService.php#L64). Dispatch
  suppression short-circuits `blockingReason` [:697](../../app/Services/AI/AnalysisService.php#L697),
  which reduces `dispatchRow` to its `firstOrCreate` in `upsertRow`
  [:379](../../app/Services/AI/AnalysisService.php#L379): a missing row is created `Pending`, an
  existing row of any status is untouched, and no job is queued. The eligibility rules are not
  restated — the athlete scan is the shared [RecentlyActiveUsers](../../app/Actions/AI/RecentlyActiveUsers.php)
  the two commands now also use, and the recap sweep is
  [KickoffWeeklyRecaps](../../app/Actions/AI/KickoffWeeklyRecaps.php) itself, so the window gate of
  [[deferred-recap-windowing]] and the demo exclusion of [[demo-user-billing-exclusion]] hold
  unchanged.
- **It covers exactly the three types self-heal can resume** — `BriefingMascotVoice`, `ProfileVoice`
  and `WeeklyRecap`. Filling stays entirely with `ai:self-heal`, so a type that sweep does not
  resume (the plan voices, notably) would only gain a row that stays empty forever. The periodizer
  half of Monday's `plan:regenerate` is also out of scope: rewriting a plan is not an upsert.
- **It is a separate command rather than a phase of `ai:self-heal`**, because the two answer to
  opposite pause rules. `ai:self-heal` early-exits while generation is paused, correctly — nothing
  it dispatches could bill. Creating a `Pending` row is free and is precisely what a paused block
  should look like per [[bounded-self-heal-and-dead-letter]], so the catch-up must keep running
  through a pause. Folding creation into a command whose first act is to stop would have inverted
  that.

### What "deliberately skipped" means here

There is no skip flag in this codebase. A day or week is skipped by the kickoff's own gates, and the
sweep inherits every one of them by reusing the same code:

- **a dormant athlete** — no run inside `RecentlyActiveUsers::ACTIVE_WINDOW_DAYS`, so no briefing or
  profile voice was ever owed;
- **the demo account** — `notDemo()` on both scans;
- **a runless or still-open week** — `runs > 0` and `week_ending <= RecapPeriod::lastClosedWeekEnding()`
  in `KickoffWeeklyRecaps`;
- **a row that already exists, in any status** — `firstOrCreate` never touches it, so a `Done` row
  keeps its prose and a dead-lettered `Failed` row stays failed and stays visible on
  `/devtools/ai-usage` rather than being quietly reopened as `Pending`;
- **a past day** — the sweep only ever names today's date and the current ISO week. Yesterday's
  missing briefing stays missing; a briefing is a today artifact and the daily kickoff never
  backfilled one either.

## Consequences

- **Enables:** a scheduler outage across a kickoff minute costs at most an hour, not a day or a
  week. Recovery is free at creation time and bills only what the existing self-heal would have.
- **Costs:** one more hourly entry in a schedule that is already busy, and two ordinary queries per
  hour when there is nothing to create. The sweep cannot recover a `plan:regenerate` that never ran.
- **Gotchas:** the cost ceiling does **not** apply at creation — suppression short-circuits the
  budget check too. That is intentional (a `Pending` row spends nothing), and the ceiling still
  applies where it matters, when self-heal or a job goes to fill it.

## See also

- [[bounded-self-heal-and-dead-letter]] — the fill side this is the creation-side companion to.
- [[deferred-recap-windowing]] — the open-week gate the recap half of the sweep inherits.
- [[demo-user-billing-exclusion]] — the exclusion both scans carry.
- [[llm-triggers]] — the scheduled-trigger table this command is listed in.
