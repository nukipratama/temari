---
title: Recaps (weekly / monthly / persona)
description: Temari's narrative recaps surfaced across the app — weekly on Jejak, monthly on Kalender, persona/profile voice on Aku
tags: [feature, recaps]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/components/aktivitas/RingkasanCard.tsx
  - resources/js/pages/Riwayat/Jejak.tsx
  - resources/js/pages/Riwayat/Kalender.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
  - app/Http/Controllers/RunController.php
  - app/Http/Controllers/CalendarController.php
  - app/Http/Controllers/ProfileController.php
---

# Recaps (weekly / monthly / persona)

Temari narrates the runner's history at three cadences — per **week**, per **month**, and a rolling **persona** read. This note covers where each narrative is *rendered* and which controller feeds it. The generation mechanics live in [[ai-pipeline]], the "don't generate the open period yet" rule in [[deferred-recap-windowing]], and the prev-link continuity in [[chained-narration]].

**No dedicated route** — recaps render inline on [[run-history]] (Jejak/Kalender) and [[profile]] (Aku) pages.

## System dependencies

- **AI pipeline** — every recap is an `Analysis` row from [[ai-pipeline]]; weekly = `AnalysisType::WeeklyRecap`, monthly = `MonthlyRecap`, profile = `AkuProfileVoice` / `PersonaSummary`.
- **Windowing** — the open week/month is gated by [[deferred-recap-windowing]]; chaining is handled by [[chained-narration]].
- **Training metrics** — weekly recaps read `TrainingLoad` / `WeeklySnapshot` from [[training-load-metrics]].
- **Notifications** — completed recaps fan out to [[telegram-notifications]].

Every recap is an `Analysis` row surfaced through the shared [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) state machine, which handles spinner / failed / empty / "Coba lagi" / "Baca ulang", and — for recaps — the `chained` + `isChainHead` + `awaitingSchedule` flags.

## Weekly recap — on Jejak

Rendered inside each week section on [Jejak](resources/js/pages/Riwayat/Jejak.tsx) via [RingkasanCard](resources/js/components/aktivitas/RingkasanCard.tsx) (the "Catatan Temari" block beside a form-status-posed Temari). `RingkasanCard` is `chained`, forwards `isChainHead`, and keeps a rule-based `fallback` (`ruleBasedFallback` in Jejak — "Minggu ini kamu lari Nx sejauh …") visible whenever `analysis.status !== 'done'`, so the block never looks empty.

[RunController](app/Http/Controllers/RunController.php) supplies it: each `WeeklySnapshot` is mapped with `recap_analysis` (from `recapAnalysesFor`, type `AnalysisType::WeeklyRecap`), `is_current_week` (the in-progress week → `awaitingSchedule`, trigger suppressed), and `is_chain_head` (`chainHeadId` = latest completed week with runs > 0, the only link that may regenerate).

## Monthly recap — on Kalender

Rendered by the local `MonthlyRecapCard` in [Kalender](resources/js/pages/Riwayat/Kalender.tsx), above the calendar grid as "Catatan Temari · {monthLabel}". Temari wears the month's dominant run mood (`dominantMoodOf` → `MOOD_TO_POSE`). It uses `AnalysisStatus` `chained` with `isChainHead={recap.is_chain_head}` and, for the current month, `awaitingSchedule` with the label "Recap bulan ini belum tersedia." There is **no rule-based fallback** for monthly — unfilled past months simply show the empty / resume state.

[CalendarController](app/Http/Controllers/CalendarController.php) keys the recap by `Y-m` discriminator (`AnalysisType::MonthlyRecap`) and computes `is_chain_head` via `latestNarratedMonthFor` (the latest closed month with a run). The page type aliases this as `MonthlyRecap = AnalysisPayload & { is_chain_head: boolean }`.

## Persona / profile voice — on Aku

The profile page surfaces two more Temari narratives (see [[profile]]):
- **`profileVoice`** ("Kata Temari tentang kamu") — `AnalysisType::AkuProfileVoice`, keyed with **no discriminator** (one per user), refreshed manually.
- **`personaSummary`** — `AnalysisType::PersonaSummary`, keyed **per ISO week**, narrating the 12-week `PersonaBar` mix.

Both come from [ProfileController](app/Http/Controllers/ProfileController.php) (`resolveProfileVoice`, `resolvePersonaSummary`) and render via plain (non-chained) `AnalysisStatus` blocks.

## Notes / gotchas

- `RecapCard` (`resources/js/components/dashboard/RecapCard.tsx`, the "Minggu Kamu" sky panel with share modal) exists and is fully tested but is **not currently mounted on any live page** — `<RecapCard>` appears only in its test file. The dashboard controller still passes a `weeklyRecap` prop that no page consumes today. Treat it as dormant until re-wired.
- Weekly and monthly are **chained**: "Coba lagi" / "Minta Temari bacain" resume the chain from the earliest unfilled link; "Baca ulang" (regenerate) shows only on the chain head, so re-narrating mid-history can't desync later links. See [[chained-narration]].
- The open week/month is **window-gated** (`awaitingSchedule`): its pending row is a "recap incoming" signal, not backlog. See [[deferred-recap-windowing]].
- Underlying rows are `Analysis` records — see [[data-model]] and [[ai-pipeline]].
