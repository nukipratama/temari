---
title: Recaps (weekly / monthly / persona)
description: Temari's narrative recaps surfaced across the app — weekly on the Feed, monthly on the Calendar, persona/profile voice on Profile
tags: [feature, recaps]
status: living
reviewed: 2026-11-09
code_refs:
  - resources/js/components/history/RecapCard.tsx
  - resources/js/pages/Activities/Feed.tsx
  - resources/js/components/history/WeekSection.tsx
  - resources/js/components/history/WeeklyStatLine.tsx
  - app/Services/AI/Narrators/WeeklyRecapNarrator.php
  - app/Services/AI/Narrators/MonthlyRecapNarrator.php
  - resources/js/pages/Activities/Calendar.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
  - app/Http/Controllers/HistoryController.php
  - app/Http/Controllers/ProfileController.php
---

# Recaps (weekly / monthly / persona)

Temari narrates the runner's history at three cadences — per **week**, per **month**, and a rolling **persona** read. This note covers where each narrative is *rendered* and which controller feeds it. The generation mechanics live in [[ai-pipeline]], the "don't generate the open period yet" rule in [[deferred-recap-windowing]], and the prev-link continuity in [[chained-narration]].

**No dedicated route** — recaps render inline on [[run-history]] (Feed/Calendar, both behind `/history`) and [[profile]] pages.

## System dependencies

- **AI pipeline** — every recap is an `Analysis` row from [[ai-pipeline]]; weekly = `AnalysisType::WeeklyRecap`, monthly = `MonthlyRecap`, profile = `ProfileVoice`.
- **Windowing** — the open week/month is gated by [[deferred-recap-windowing]]; chaining is handled by [[chained-narration]].
- **Training metrics** — weekly recaps read `TrainingLoad` / `WeeklySnapshot` from [[training-load-metrics]].
- **Notifications** — completed recaps fan out to [[telegram-notifications]].

Every recap is an `Analysis` row surfaced through the shared [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) state machine, which handles skeleton / failed / "Try again" / "Reread", and — for recaps — the `chained` + `isChainHead` + `awaitingSchedule` flags. A plain `pending` block renders nothing at all; only an `awaitingSchedule` one explains itself.

## Weekly recap — on the run log (`/history`, list view)

Rendered inside each [WeekSection](resources/js/components/history/WeekSection.tsx) via the shared [RecapCard](resources/js/components/history/RecapCard.tsx) (a mood-ringed Temari beside a single column: the metric chips on one wrapping row, then the narration under them). `RecapCard` is `chained`, forwards `isChainHead`, and keeps a rule-based `fallback` (`ruleBasedFallback`, alongside it — "You ran Nx this week for N km.") visible whenever `analysis.status !== 'done'`, so the block never looks empty.

The chips are the [WeeklyStatLine](resources/js/components/history/WeeklyStatLine.tsx): **load balance** and **drift** only, drift showing only when the week has a version 2 decoupling reading. Short-term load and variety (monotony) are not chips: the story carries them when they matter.

**Shape.** [WeeklyRecapNarrator](app/Services/AI/Narrators/WeeklyRecapNarrator.php) writes a **headline** (one short sentence, the takeaway) and a story of 200-280 words in **2-3 paragraphs** (one blank line apart, each at least three connected sentences and one beat of the same reading), stored as JSON `{title, body}` content and rendered by `AnalysisStatus` as the headline over the body, whose `whitespace-pre-line` keeps the paragraph breaks; `RecapCard` caps its paragraphs at `max-w-prose`. The model first commits to one `reading` (`vs_last_week`, `plan`, `standout_run`, `records`, `long_arc` or `load`), which is required but never stored; every number belongs to that reading, at most 7 across headline and body. Rows written before the redesign, and the rule-based filler, are plain text and render as body only. `RecapCard` opts into `AnalysisStatus`'s `collapsible`: a titled story of more than one paragraph shows the headline and its first paragraph, then a lowercase `read more` / `show less` toggle (`aria-expanded`, `aria-controls` the story block) under the text, left of the Reread control, with no animation and no remembered state, so every mount starts collapsed. An untitled row or a single-paragraph story has no toggle. The Calendar's week row and the monthly card below collapse the same way.

**Tools.** The narrator fetches the week itself: `get_week_totals` ([WeekTotalsTool](app/Services/AI/Agent/Tools/WeekTotalsTool.php), totals, load and last week), `get_week_runs` ([WeekRunsTool](app/Services/AI/Agent/Tools/WeekRunsTool.php), every run Monday to Sunday with its plan match, grade and one-line verdict), `get_planned_sessions` ([PlanContextTool](app/Services/AI/Agent/Tools/PlanContextTool.php)), `get_week_records` ([WeekRecordsTool](app/Services/AI/Agent/Tools/WeekRecordsTool.php), standing PRs a run that week set) and `get_trend_range_totals` ([TrendRangeTool](app/Services/AI/Agent/Tools/TrendRangeTool.php) over `6w`, read as of the week's Sunday so a regenerated past week reads the arc as it stood then). Every tool is bound to the snapshot's own week, never to today.

`HistoryController`'s list branch supplies it: each `WeeklySnapshot` is mapped with `recap_analysis` (from `recapAnalysesFor`, type `AnalysisType::WeeklyRecap`), `is_current_week` (the in-progress week → `awaitingSchedule`, trigger suppressed), and `is_chain_head` (`chainHeadId` = latest completed week with runs > 0, the only link that may regenerate).

## Monthly recap — on the calendar (`/history?view=calendar`)

Rendered by the same shared [RecapCard](resources/js/components/history/RecapCard.tsx) in [Calendar](resources/js/pages/Activities/Calendar.tsx), above the calendar grid. Temari wears the month's dominant run mood (`dominantMoodOf` → `MOOD_TO_POSE`). It uses `AnalysisStatus` `chained` with `isChainHead={recap.is_chain_head}` and, for the current month, `awaitingSchedule` with the label "This month's recap isn't ready yet." There is **no rule-based fallback** for monthly (`RecapCard`'s `fallback` prop is omitted here) — an unfilled past month shows nothing until it fails, at which point "Try again" resumes the chain. Unlike the weekly card, the monthly `RecapCard` carries no `chips` — the month header above the calendar grid already prints the runs / distance / TRIMP numbers ([Calendar.tsx](resources/js/pages/Activities/Calendar.tsx)).

**Shape.** [MonthlyRecapNarrator](app/Services/AI/Narrators/MonthlyRecapNarrator.php) writes the same **headline** and 200-280 word, 2-3 paragraph story as the weekly (stored as `{title, body}` content, rendered by `AnalysisStatus` and capped at `max-w-prose` by `RecapCard`), after committing to one `reading`: `vs_last_month`, `plan`, `standout_run`, `records`, `long_arc`, `load` or `consistency` (how the weeks inside the month compared). The reading is required but never stored; every number belongs to it, at most 7 across headline and body. `weekly_distance_km` is in seven-day buckets from the 1st, so a final bucket of one to three days (29 February included) is a partial week the prompt never reads as a drop. Rows written before the redesign are plain text and render as body only. The card collapses a titled multi-paragraph story to its first paragraph behind the same `read more` toggle as the weekly one.

**Tools.** The narrator fetches the month itself: `get_month_totals` ([MonthTotalsTool](app/Services/AI/Agent/Tools/MonthTotalsTool.php), totals, pace, km per week, mood mix, long-term load and the previous calendar month's `prev_*`, null when that month has no runs), `get_month_runs` ([MonthRunsTool](app/Services/AI/Agent/Tools/MonthRunsTool.php), every run of the month with its plan match, grade and one-line verdict), `get_planned_sessions` ([PlanContextTool](app/Services/AI/Agent/Tools/PlanContextTool.php)), `get_month_records` ([MonthRecordsTool](app/Services/AI/Agent/Tools/MonthRecordsTool.php), standing PRs a run that month set) and `get_trend_range_totals` ([TrendRangeTool](app/Services/AI/Agent/Tools/TrendRangeTool.php) over `6w`, read as of the month's last day). The runs and records tools share their logic with the weekly pair through `PeriodRunsTool` and `PeriodRecordsTool`, and the month totals count the same analysed runs as the runs tool.

`HistoryController`'s calendar branch keys the recap by `Y-m` discriminator (`AnalysisType::MonthlyRecap`) and computes `is_chain_head` via `latestNarratedMonthFor` (the latest closed month with a run). The page type aliases this as `MonthlyRecap = AnalysisPayload & { is_chain_head: boolean }`.

## Persona / profile voice — on Profile

The profile page surfaces one more Temari narrative (see [[profile]]): **`profileVoice`** ("What Temari says about you"), `AnalysisType::ProfileVoice`, keyed **per ISO week**. It carries both readings the page used to bill separately, the 12-week mood persona and the lifetime/progression numbers, in a single call.

It comes from [ProfileController](app/Http/Controllers/ProfileController.php) (`resolveProfileVoice`) and renders via a plain (non-chained) `AnalysisStatus` block. `ai:weekly-profile` re-narrates it once a week with `invalidate: false`, so a mid-week "Reread" is never re-billed by the scheduler. Because the voice quotes the settled weekly streak, every automatic request waits until that athlete's streak is settled through the closed week and not dirty; `ai:self-heal` narrates it once they are, and the athlete's own "Reread" does not wait (see [[scheduler]]).

## Notes / gotchas

- Weekly and monthly are **chained**: "Try again" on a failed link resumes the chain from the earliest unfilled one; "Reread" (regenerate) shows only on the chain head, so re-narrating mid-history can't desync later links. See [[chained-narration]].
- The open week/month is **window-gated** (`awaitingSchedule`): its pending row is a "recap incoming" signal, not backlog. See [[deferred-recap-windowing]].
- A **first Strava connect** runs the weekly + monthly kickoff once for that user, chained behind the history backfill ([KickoffRecapsJob](app/Jobs/AI/KickoffRecapsJob.php)), so a new runner's history narrates on day one instead of waiting for the Monday kickoff or the month close, after the hydration drain has filled each recap period in. It reads only rows the backfill already wrote, so it costs no Strava budget, and it skips every `Done` recap, so a re-run bills nothing.
- Weekly and monthly recaps wait while their period still holds runs the ingest pipeline owes a detail fetch, so neither permanently records partial load or totals; past a wall-clock grace window each narrates what exists. See [[recap-waits-for-hydration]].
- Underlying rows are `Analysis` records — see [[data-model]] and [[ai-pipeline]].
