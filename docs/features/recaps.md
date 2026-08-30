---
title: Recaps (weekly / monthly / persona)
description: Temari's narrative recaps surfaced across the app — weekly on the Feed, monthly on the Calendar, persona/profile voice on Profile
tags: [feature, recaps]
status: living
reviewed: 2026-08-19
code_refs:
  - resources/js/components/history/RecapCard.tsx
  - resources/js/pages/Activities/Feed.tsx
  - resources/js/components/history/WeekSection.tsx
  - resources/js/pages/Activities/Calendar.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
  - app/Http/Controllers/HistoryController.php
  - app/Http/Controllers/ProfileController.php
---

# Recaps (weekly / monthly / persona)

Temari narrates the runner's history at three cadences — per **week**, per **month**, and a rolling **persona** read. This note covers where each narrative is *rendered* and which controller feeds it. The generation mechanics live in [[ai-pipeline]], the "don't generate the open period yet" rule in [[deferred-recap-windowing]], and the prev-link continuity in [[chained-narration]].

**No dedicated route** — recaps render inline on [[run-history]] (Feed/Calendar, both behind `/history`) and [[profile]] pages.

## System dependencies

- **AI pipeline** — every recap is an `Analysis` row from [[ai-pipeline]]; weekly = `AnalysisType::WeeklyRecap`, monthly = `MonthlyRecap`, profile = `AkuProfileVoice`.
- **Windowing** — the open week/month is gated by [[deferred-recap-windowing]]; chaining is handled by [[chained-narration]].
- **Training metrics** — weekly recaps read `TrainingLoad` / `WeeklySnapshot` from [[training-load-metrics]].
- **Notifications** — completed recaps fan out to [[telegram-notifications]].

Every recap is an `Analysis` row surfaced through the shared [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) state machine, which handles skeleton / failed / "Try again" / "Reread", and — for recaps — the `chained` + `isChainHead` + `awaitingSchedule` flags. A plain `pending` block renders nothing at all; only an `awaitingSchedule` one explains itself.

## Weekly recap — on the run log (`/history`, list view)

Rendered inside each [WeekSection](resources/js/components/history/WeekSection.tsx) via the shared [RecapCard](resources/js/components/history/RecapCard.tsx) (a mood-ringed Temari beside the narration, metric chips and the "Send notification" trigger underneath). `RecapCard` is `chained`, forwards `isChainHead`, and keeps a rule-based `fallback` (`ruleBasedFallback`, alongside it — "You ran Nx this week for N km.") visible whenever `analysis.status !== 'done'`, so the block never looks empty.

`HistoryController`'s list branch supplies it: each `WeeklySnapshot` is mapped with `recap_analysis` (from `recapAnalysesFor`, type `AnalysisType::WeeklyRecap`), `is_current_week` (the in-progress week → `awaitingSchedule`, trigger suppressed), and `is_chain_head` (`chainHeadId` = latest completed week with runs > 0, the only link that may regenerate).

## Monthly recap — on the calendar (`/history?view=calendar`)

Rendered by the same shared [RecapCard](resources/js/components/history/RecapCard.tsx) in [Calendar](resources/js/pages/Activities/Calendar.tsx), above the calendar grid. Temari wears the month's dominant run mood (`dominantMoodOf` → `MOOD_TO_POSE`). It uses `AnalysisStatus` `chained` with `isChainHead={recap.is_chain_head}` and, for the current month, `awaitingSchedule` with the label "This month's recap isn't ready yet." There is **no rule-based fallback** for monthly (`RecapCard`'s `fallback` prop is omitted here) — an unfilled past month shows nothing until it fails, at which point "Try again" resumes the chain.

`HistoryController`'s calendar branch keys the recap by `Y-m` discriminator (`AnalysisType::MonthlyRecap`) and computes `is_chain_head` via `latestNarratedMonthFor` (the latest closed month with a run). The page type aliases this as `MonthlyRecap = AnalysisPayload & { is_chain_head: boolean }`.

## Persona / profile voice — on Profile

The profile page surfaces one more Temari narrative (see [[profile]]): **`profileVoice`** ("What Temari says about you"), `AnalysisType::AkuProfileVoice`, keyed **per ISO week**. It carries both readings the page used to bill separately, the 12-week mood persona behind `PersonaBar` and the lifetime/progression numbers, in a single call.

It comes from [ProfileController](app/Http/Controllers/ProfileController.php) (`resolveProfileVoice`) and renders via a plain (non-chained) `AnalysisStatus` block. `ai:weekly-profile` re-narrates it once a week with `invalidate: false`, so a mid-week "Reread" is never re-billed by the scheduler.

## Notes / gotchas

- Weekly and monthly are **chained**: "Try again" on a failed link resumes the chain from the earliest unfilled one; "Reread" (regenerate) shows only on the chain head, so re-narrating mid-history can't desync later links. See [[chained-narration]].
- The open week/month is **window-gated** (`awaitingSchedule`): its pending row is a "recap incoming" signal, not backlog. See [[deferred-recap-windowing]].
- Underlying rows are `Analysis` records — see [[data-model]] and [[ai-pipeline]].
