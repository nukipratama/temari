---
title: Demo user excluded from auto-billing schedulers
description: The seeded demo account carries is_demo and is excluded from every recurring token/Strava-billing scheduler; its content is backfilled rule-based.
tags: [decision, ai]
status: accepted
reviewed: 2026-06-20
code_refs:
  - app/Models/User.php
  - app/Console/Commands/AI/WeeklyRecapCommand.php
  - app/Console/Commands/AI/MonthlyRecapCommand.php
  - app/Console/Commands/AI/ResumeChainsCommand.php
  - app/Console/Commands/AI/WeeklyProfileCommand.php
  - app/Console/Commands/AI/DailyBriefingCommand.php
  - app/Console/Commands/Strava/SyncCommand.php
  - app/Console/Commands/Strava/IngestCommand.php
  - app/Console/Commands/DemoSeedCommand.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
---

# Demo user excluded from auto-billing schedulers

**Status:** Accepted (documented 2026-06-20)

## Context

The `/login` "Coba versi demo" button signs a reviewer into a seeded demo account. That account is fully populated so every page renders, but it has no real Strava connection and we don't want it spending real Strava calls or LLM tokens on a recurring schedule. We needed the demo to look complete while costing zero on the cron path.

## Decision

We decided the demo account carries an `is_demo` flag and is **excluded from every recurring kickoff/billing scheduler**, so it never auto-spends Strava calls or LLM tokens on the cron path.

The flag and its scope live on [User](app/Models/User.php): an `is_demo` boolean cast plus a local `scopeNotDemo` (`where('is_demo', false)`). Per the code as it stands, every kickoff scheduler scopes with `notDemo()` / `is_demo = false`:

- `ai:daily-briefing` (briefing set + trend caption) — [DailyBriefingCommand](app/Console/Commands/AI/DailyBriefingCommand.php)
- `ai:weekly-recap` — [WeeklyRecapCommand](app/Console/Commands/AI/WeeklyRecapCommand.php)
- `ai:weekly-profile` (persona summary + Kata Temari voice) — [WeeklyProfileCommand](app/Console/Commands/AI/WeeklyProfileCommand.php)
- `ai:monthly-recap` — [MonthlyRecapCommand](app/Console/Commands/AI/MonthlyRecapCommand.php)
- `strava:sync` — [SyncCommand](app/Console/Commands/Strava/SyncCommand.php)
- `strava:ingest` — [IngestCommand](app/Console/Commands/Strava/IngestCommand.php) (filters `is_demo = false` via a relation sub-query)

The one daily sweep that does **not** filter demo is the **per-activity** branch of [ResumeChainsCommand](app/Console/Commands/AI/ResumeChainsCommand.php) (`resumePerActivity()`), and that is deliberate per its docblock: it is a safety net that only re-kicks a *Pending* PostRunSpeech group, and the demo's per-activity rows are rule-based seeded (Done), so it has nothing Pending to re-kick. The weekly and monthly sweeps of the same command do exclude demo.

The demo's narration is otherwise backfilled deterministically with zero tokens: [DemoSeedCommand](app/Console/Commands/DemoSeedCommand.php) seeds the dataset, and [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) provides Temari-voiced content per AnalysisType under `AnalysisService::withoutDispatching()`. The live "Baca ulang" button still lets a reviewer trigger a real per-block LLM call on demand.

## Consequences

- **Enables:** a demo that renders complete on every page at zero recurring token/Strava cost; reviewers still upgrade any block to a real LLM narrative via the live "Baca ulang" button.
- **Costs:** demo narration is deterministic seed copy by default, not live LLM prose, unless a reviewer triggers it manually.
- **Gotchas:** the exclusion is a **local** scope (`scopeNotDemo`), not a global one, so the demo stays fully visible in the app UI. Relation sub-queries can't see the named scope on a generically-typed builder, so they filter `is_demo` directly (`whereHas('user', fn ($q) => $q->where('is_demo', false))`, as in `strava:ingest`). Adding a new billing scheduler does **not** auto-exclude demo — each command must apply `notDemo()` itself, and the `resumePerActivity()` safety net intentionally does not (it relies on demo rows being seeded Done).

## See also

- [[ai-pipeline]] — the narrator/analysis pipeline these schedulers feed
- [[deferred-recap-windowing]] — why the recaps are scheduled at all
- [[chained-narration]] — the resume-chains safety net whose per-activity sweep is the one place demo isn't filtered
