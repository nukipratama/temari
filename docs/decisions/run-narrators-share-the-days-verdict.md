---
title: Run narrators share the day's verdict
description: The post-run speech, the run insight and the post-run briefing repeat the stored intent verdict and its numbers, never their own pace comparison.
tags: [decision, ai, run]
status: accepted
reviewed: 2026-10-15
code_refs:
  - app/Services/AI/Narrators/PlanVerdictRule.php
  - app/Services/AI/Agent/Tools/PlanContextTool.php
  - app/Services/AI/Narrators/PostRunSpeechNarrator.php
  - app/Services/AI/Narrators/RunInsightNarrator.php
  - app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php
  - app/Services/Run/Plan/IntentOutcome.php
---

# Run narrators share the day's verdict

**Status:** Accepted (2026-10-15)

## Context

Three narrators write about a finished run: the post-run speech and the run insight on the run's page, and the post-run briefing on the dashboard. In prod they disagreed about the same run.

- **A planned tempo with reps faster than threshold.** The speech compared the whole-run average, warm-up and cool-down included, with the threshold target, and called the run "easier than written". The briefing said it overreached, and the insight called it heavy.
- **An easy day in the heat with a large Z3 share.** The speech said it "stayed honest", the insight said it "read heavy", and the briefing said it stayed easy.
- **A tempo readiness eased to easy, run as the tempo anyway.** The narration said "the plan had this as an easy day" and never named the easing.

A deterministic verdict already existed for all of these. [SessionIntentJudge](app/Services/Run/Plan/SessionIntentJudge.php) grades every run that day against the effective session, [ComplianceScorer](app/Services/Run/Plan/ComplianceScorer.php) stores it as `intent_verdict`/`intent_evidence`, and [IntentOutcome](app/Services/Run/Plan/IntentOutcome.php) words it. [PlanContextTool](app/Services/AI/Agent/Tools/PlanContextTool.php) already handed the narrators the verdict's clause (`intent`), but not its numbers, and only the briefing prompt treated it as final.

## Decision

**The stored verdict is the one effort judgement, and every run narrator repeats it.**

- `PlanContextTool` adds `intent_detail` beside `intent`: the numbers behind the verdict, already compared, from `IntentOutcome::detail()`. The run's own pace is not passed in, so an average it quotes is the judged pace.
- [PlanVerdictRule::RULE](app/Services/AI/Narrators/PlanVerdictRule.php) is appended to the speech, the insight and the post-run briefing prompts (the morning briefing has no finished day to judge). It says:
  - never contradict or soften the verdict;
  - never compare the overall average pace with the target pace, because a quality day's target covers only the hard work; take numbers from `intent_detail`;
  - name an easing (`eased_from`) once, and say when the original session was run anyway;
  - on an easy or long day, heart rate that ran hot beside an easy verdict is "the heart rate ran hot", never "the day stopped being easy";
  - a day of several runs is judged on the day's total;
  - with no verdict, hand down none.
- The insight's grey-zone nudge toward easy pace also stands down when the verdict says the day stayed at the easy effort it asked for.

The rule ships as a prompt, so the next layer of #2138 adds a code check on the speech (cited figures and verdict direction) before it is saved.

## Consequences

- Every narrator that reads `PlanContextTool` now sees `intent_detail`, the recaps and card flavour included. It is one short string per judged day.
- A day judged after its run was narrated doesn't re-narrate on its own; the verdict reaches the narration on the next generation.
- This was checked against tests, not a live model. Re-narrating the owner's recent runs after the stack lands is the end-to-end check.

Related: [[a-day-is-graded-on-distance-and-intent]], [[grading-follows-shown-advice-and-actual-stimulus]], [[session-intent-reads-the-plan]].
