---
title: Race-specific sessions name the pace they run at
description: Ambitious stepping-stone work says it closes at goal pace, a fast-finish long run is named as one with its fast block's real pace, the plan tools hand narrators the same, and the Race page shows an ambitious goal its stepping stone.
tags: [decision, run, plan]
status: accepted
reviewed: 2026-10-12
code_refs:
  - app/Services/Run/Plan/FastFinishLongRun.php
  - app/Services/Run/Plan/PlanRenderer.php
  - app/Services/Run/Plan/RaceAmbitionAssessor.php
  - app/Services/AI/Agent/Tools/PlanContextTool.php
  - resources/js/lib/plan.ts
  - resources/js/components/plan/DayDetail.tsx
  - resources/js/components/plan/SessionBarGraph.tsx
  - resources/js/components/race/SteppingStoneCard.tsx
---

# Race-specific sessions name the pace they run at

**Status:** Accepted (2026-10-12). Copy layer of #2116, layer 5. Supersedes rules 3 and 4 of [[an-ambitious-goal-trains-at-the-stepping-stone-and-rehearses-the-goal]].

## Context

After the first four layers, an ambitious goal's goal-pace work closed at goal pace but read only "rehearsing your 10K stepping-stone pace". A fast-finish long run read "long run with goal-pace work" on every surface, even when its fast block ran at a stepping-stone or supported pace, and narrators were handed it as a plain long run at easy pace. The Race page showed the stepping stone only to an unsupported goal, although an ambitious goal now trains at it too.

## Decision

1. **Ambitious closing.** Stepping-stone work that closes at goal pace keeps the "stepping-stone pace" label, and its purpose line reads "rehearsing your 10K stepping-stone pace, closing at goal pace." The day payload carries `closes_at_goal_pace` ([PlanRenderer::dayPayload()](app/Services/Run/Plan/PlanRenderer.php), [sessionPurpose()](resources/js/lib/plan.ts)). The session bar's legend reads a rep or block split at its close as one, and names the close under it: "last at 5:48/km · goal pace" for a final goal-pace rep, "last quarter of the last rep at …" when fewer reps close inside the last one, "last quarter of each at …" for blocks, and "last quarter at …" for a single block ([SessionBarGraph](resources/js/components/plan/SessionBarGraph.tsx)). **Product choice.**
2. **Fast-finish label and pace.** A fast-finish long run is labelled "long run with a fast finish" on Home's today session and the Plan day detail. Its fast block's segment names the pace it runs at, "goal pace", "stepping-stone pace" or "supported 10K pace", and the purpose line reads, for example, "the last fifth at your supported 10K pace. rehearses race pace on tired legs." It never says goal pace unless the block runs at it. The week strip tile keeps "long". The row stamps `pace_kind` and `pace_source` (`goal`, `stepping_stone`, `supported`) in `prescription_race_context` ([FastFinishLongRun::context()](app/Services/Run/Plan/FastFinishLongRun.php)), and the day payload carries them as `fast_finish` ([PlanRenderer::fastFinishOf()](app/Services/Run/Plan/PlanRenderer.php), [fastFinishPaceLabel()](resources/js/lib/plan.ts)). **Product choice.**
3. **Narration.** The plan tools hand narrators `closing_goal_pace_formatted` beside `stepping_stone` for ambitious work ([PlanRenderer::goalPaceForNarration()](app/Services/Run/Plan/PlanRenderer.php)), and `fast_finish` with its pace kind, pace source, minutes and formatted pace on a fast-finish long run ([PlanRenderer::fastFinishForNarration()](app/Services/Run/Plan/PlanRenderer.php)). The [PlanContextTool](app/Services/AI/Agent/Tools/PlanContextTool.php) description tells the narrator to call it a long run with a fast finish, and goal pace only when the source is goal. **Product choice.**
4. **Race page.** [RaceAmbitionAssessor::assess()](app/Services/Run/Plan/RaceAmbitionAssessor.php) sets the stepping stone for an ambitious goal as well as an unsupported one, so the race payload carries it and [SteppingStoneCard](resources/js/components/race/SteppingStoneCard.tsx) shows it. For an ambitious goal its line reads "the edge of on track, 3% faster than your supported time. you train here and close each session at your goal pace, and it moves as you get fitter." The plan reads the same stepping stone from the payload ([PlanInputsGatherer](app/Services/Run/Plan/PlanInputsGatherer.php)). **Product choice.**

## Consequences

- An ambitious athlete sees the pace they train at on the Race page, and every surface names the goal-pace close.
- A fast finish at a stepping-stone or supported pace is never called goal-pace work, on screen or in a written read.
- A fast-finish row stamped before this layer carries no `pace_kind` or `pace_source` and keeps the layer 4 copy; the owner's `plan:regenerate` after deploy restamps the rows ahead.

## See also

- [[an-ambitious-goal-trains-at-the-stepping-stone-and-rehearses-the-goal]], [[a-fast-finish-long-run-for-5k-to-half-goals]], [[a-stepping-stone-for-an-unsupported-goal]], [[race-projection]], [[plan-periodizer]]
