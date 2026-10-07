---
title: A plan day has no narrated read
description: The per-day "Temari's read" (plan_day_voice) is removed with no replacement; a day's verdict stays in its grade and on the day card, and its stored reads and their flags are deleted.
tags: [decision, ai, plan]
status: accepted
reviewed: 2026-10-07
code_refs:
  - app/Services/AI/PlanNarrationRequester.php
  - app/Services/Run/Plan/PlanPageAssembler.php
  - resources/js/components/plan/DayDetail.tsx
  - database/migrations/2026_10_07_010000_delete_plan_day_voice_analyses.php
---

# A plan day has no narrated read

**Status:** Accepted (2026-10-07), owner decision #1912. Supersedes the day-read parts of [[history-narrates-on-demand]], [[narration-spends-only-on-active-athletes]], [[the-eased-session-leads]], [[the-clamp-explains-itself]], [[readiness-clamp-is-advisory]] and [[a-make-up-is-graded-against-the-moved-session]]. Supersedes #1910 and PR #1911.

## Context

Since #939 a credited day of the current week carried one narrated line, "Temari's read" (`plan_day_voice`), saying whether the run did the job the planned session asked for. It was requested after a run credited the day, after a plan edit or make-up touched a credited day, on an athlete's return, when a fresh connect's history landed, and by `plan:regrade-season`, and it was re-narrated whenever the day's material fingerprint moved.

- It mostly repeated the day card beside it and the run detail's narration (post-run speech and run insight).
- It cost an LLM call per credited day, plus re-reads after edits and make-ups.
- It kept producing edge cases; #1910 read an emptied make-up day as honored rest.
- It only ever showed for the current week. Past weeks never had one.

## Decision

- **No per-day read, and no replacement line.** The day panel shows the day card's numbers, notes and actions only. Nothing requests, settles, backfills, demo-fills or re-narrates a day read, and the `plan_day_voice` type, its narrator, job and tool are gone.
- **The verdict stays in the grade.** Whether a run did the session's job is still graded (`intent_verdict`, `distance_score`) and shown on the day card. It is no longer put into words.
- **Stored reads are deleted.** A migration deletes every `plan_day_voice` row and the `narration` flags on them; their versions and notification deliveries cascade. The cost history in `ai_token_usages` on the analytics connection is kept. The text cannot be restored, so `down()` does nothing.
- **Everything else is unchanged.** The season voice, the clamp voice, the briefing and run narration keep working, and flagging a prescribed day (`plan_day` feedback) is a separate feature that stays.

## Consequences

- One fewer LLM call per credited day, and none after edits, make-ups or returns.
- `narration:eval` covers the briefing, run insight and profile voice only.
- An athlete who flagged a day read loses that flag with the read.

## See also

- [[plan-periodizer]], [[dashboard]], [[llm-triggers]], [[ai-narration-internals]].
