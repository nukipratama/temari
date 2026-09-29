---
title: Ask-about-this-run is a conversation about one run
description: The Q&A surface becomes multi-turn. The model reads earlier exchanges through an argument-free get_thread tool, each answer offers up to two follow-ups from the same call, and a per-run daily cap refuses the eleventh question outright. Scope stays one run.
tags: [decision, ai]
status: accepted
reviewed: 2026-09-29
code_refs:
  - app/Services/AI/Agent/Tools/GetThreadTool.php
  - app/Services/AI/Narrators/RunQuestionNarrator.php
  - app/Services/AI/RunQuestion/RunQuestionSeeds.php
  - app/Http/Controllers/Api/RunQuestionController.php
  - app/Jobs/AI/AnswerRunQuestionJob.php
  - config/ai.php
  - resources/js/components/run/AskAboutRun.tsx
---

# Ask-about-this-run is a conversation about one run

**Status:** Accepted (decided 2026-09-29). Supersedes only the "no chat" part of
[[scoped-run-qa-not-an-analysis-row]]; everything else in that decision stands.

## Context

[[scoped-run-qa-not-an-analysis-row]] shipped the Q&A as one stateless question
per call: the narrator sent only the question text. A follow-up like "why?" or
"what about km 5?" was answered as if it were the first question, so the natural
next thing a runner types got a worse answer than the first one did.

The reasons for "no chat" were about **range**, not turns: a general assistant
would range over the whole account, invite questions the data cannot answer, and
put an unbounded surface behind the demo. None of that needs one question at a
time. It needs the toolbox bound to one run, which it already is.

## Decision

**Scope stays one run.** The toolbox is still built from one activity and its
owner's history as of that run, and every tool is still argument-free.

**Memory is a tool, not a transcript in the prompt.**
[GetThreadTool](app/Services/AI/Agent/Tools/GetThreadTool.php) (`get_thread`) is
bound at construction to this activity, its owner and the question being
answered. It returns this run's earlier settled exchanges, oldest first, capped
to the most recent [six](app/Services/AI/Agent/Tools/GetThreadTool.php#L13). A
forged argument naming another run or user changes nothing, which the tests
assert adversarially. The prompt's THREAD section
([RunQuestionNarrator](app/Services/AI/Narrators/RunQuestionNarrator.php#L49))
tells the model to read it when the question refers back. A first question
costs nothing extra, because the model does not call it.

**Follow-ups come from the same call.** The structured output gains
`follow_ups`: zero to two short questions this run's data can answer, not yet
asked ([prompt](app/Services/AI/Narrators/RunQuestionNarrator.php#L98)). They are
stored on the row in a nullable json column. A rule-based answer (the demo, a
capped spend day) offers the run's suggested questions nobody has asked yet
instead ([`RunQuestionSeeds::unasked`](app/Services/AI/RunQuestion/RunQuestionSeeds.php#L102)).
No extra LLM call either way.

**A per-run daily cap refuses, it does not degrade.**
[`ai.run_question_daily_cap_per_run`](config/ai.php#L40) (default 10) counts one
athlete's questions on one run since local midnight. Past it,
[`store()`](app/Http/Controllers/Api/RunQuestionController.php#L72) returns `429`
with `{"error": "run_cap"}` before anything could dispatch or serve rule-based
copy, and writes no row. `index()` reports `at_run_cap` so a reload stays locked.
The demo keeps its rule-based path and is never capped, because it never bills.
The per-minute rate limit still applies on top.

**The panel stays a transcript.** Accent-rule question, `.narration` answer, no
bubbles or avatars. A "keep going" row of the follow-ups sits under the latest
settled answer only, and the cap renders as one line in Temari's voice with the
input disabled.

## Consequences

- **Enables:** follow-ups answered in context, with the scope boundary unchanged
  and still structural.
- **Costs:** a follow-up that reads the thread spends one more tool step and the
  tokens of up to six earlier exchanges. The cap bounds the thread a day can
  build on one run.
- **Gotchas:** the cap counts rows, so a failed question still counts toward it.
  It resets at the app's local midnight, not the athlete's.

## See also

- [[scoped-run-qa-not-an-analysis-row]] — the parent decision; this replaces its "no chat" stance only
- [[run-qa]] — the feature walkthrough
- [[cost-ceiling-answers-run-questions-rule-based]] — the capped-spend path, which the run cap now precedes
