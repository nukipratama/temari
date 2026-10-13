---
title: The season has no narrated read
description: The season's "Temari's take" (plan_season_voice) is removed with no replacement; the season header keeps its numbers, and its stored takes and their flags are deleted.
tags: [decision, ai, plan]
status: accepted
reviewed: 2026-10-13
code_refs:
  - app/Services/AI/ClampVoiceRequester.php
  - app/Http/Controllers/PlanController.php
  - resources/js/components/plan/SeasonHeaderCard.tsx
  - database/migrations/2026_10_13_000000_delete_plan_season_voice_analyses.php
---

# The season has no narrated read

**Status:** Accepted (2026-10-13), owner decision #2133. Supersedes the season-voice parts of [[a-plan-day-has-no-narrated-read]], [[plan-adaptation-responds-to-stimulus]] and [[plan-page-leads-with-this-week]].

## Context

The Plan page's season header carried one narrated line, "Temari's take" (`plan_season_voice`), about the season so far. It was requested after a manual regenerate, a settings or race change (behind a 24-hour cooldown), a reconciliation that changed the plan, the weekly `plan:regenerate` sweep and the first week of a new account, and re-narrated whenever the sustained-ahead-of-race-pace signal or the current week's adaptation moved.

The owner judged the block not worth its place under the phase bar or its LLM spend. The season header already carries the phase bar and rule-based season facts.

## Decision

- **No season read, and no replacement line.** The season header shows its phase, next phase, adherence, race slot, phase ribbon and under-ready line only. Nothing requests, settles, demo-fills or re-narrates a season read, and the `plan_season_voice` type, its narrator, job, tool, listener and the `PlanRegenerated` event are gone.
- **Regenerating a plan bills nothing.** A manual regenerate, a settings or race change, a reconciliation, onboarding, the backfill's first-week resize and `plan:regenerate` rewrite the plan and stop. `plan:regenerate` still starts the manual regenerate cooldown for recently active athletes.
- **Stored takes are deleted.** A migration deletes every `plan_season_voice` row and the `narration` flags on them; their versions and notification deliveries cascade. The cost history in `ai_token_usages` on the analytics connection is kept. The text cannot be restored, so `down()` does nothing.
- **Everything else is unchanged.** The clamp voice, now requested through `ClampVoiceRequester`, the briefing and run narration keep working.

## Consequences

- No LLM call on any plan regeneration.
- An athlete who flagged a season take loses that flag with the take.

## See also

- [[plan-periodizer]], [[llm-triggers]], [[ai-narration-internals]], [[a-plan-day-has-no-narrated-read]].
