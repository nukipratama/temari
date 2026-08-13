---
title: Ask-about-this-run is scoped to one activity, and is not an Analysis row
description: The Q&A surface reuses the agent loop with a toolbox bound to a single activity, but stores its exchanges in its own run_questions table rather than the ai_analyses row model, and rate-limits per user without a per-user cost cap.
tags: [decision, ai]
status: accepted
reviewed: 2026-08-13
code_refs:
  - app/Http/Controllers/Api/RunQuestionController.php
  - app/Services/AI/Narrators/RunQuestionNarrator.php
  - app/Services/AI/RunQuestion/RunQuestionSeeds.php
  - app/Services/AI/RunQuestion/RuleBasedRunAnswer.php
  - app/Jobs/AI/AnswerRunQuestionJob.php
  - app/Models/AI/RunQuestion.php
  - config/ai.php
  - routes/web.php
---

# Ask-about-this-run is scoped to one activity, and is not an Analysis row

**Status:** Accepted (documented 2026-08-13)

## Context

The product decision was explicitly **no chat**. A general assistant would have
to range over the whole account to be useful, would invite questions the data
cannot answer, and would put an unbounded LLM surface behind a public demo login.

But "why did my HR drift?" is the question a runner actually has, and it is
answerable from one run. The narrow version of the feature is a question **bound
to a single activity** — which is exactly the shape the narration agents already
have, since every tool is constructed already bound to its subject
([[narration-agents-on-openai-php]]).

Two things did not fit, though:

- The [[ai-pipeline]] row model is keyed
  `(subject_type, subject_id, analysis_type, discriminator)` and holds exactly
  one regenerable body of copy per key. A run accumulates *many* questions, each
  with different text. Encoding a question into `discriminator` (40 chars,
  unique-indexed) would be a hash of user prose in a column meant for an ISO
  week.
- Everything else in the pipeline regenerates deterministically from the same
  subject. A question does not: its content is half user input.

## Decision

**Scope is enforced by construction, not by prompt.**
[`RunQuestionNarrator::toolbox()`](app/Services/AI/Narrators/RunQuestionNarrator.php#L113)
builds the toolbox from one `Activity` and its detail, plus that owner's history
*as of that run*. Every tool is argument-free, so no phrasing of a question
reaches another run or another account. The prompt says the same thing, but the
prompt is not what enforces it — the tests assert it adversarially, by forging a
tool call carrying another activity's id and getting this run's numbers back.

**Exchanges live in their own table.**
[RunQuestion](app/Models/AI/RunQuestion.php) is a plain row per exchange
(`user_id`, `activity_id`, `question`, `answer`, `status`). It reuses
[AnalysisStatus](app/Services/AI/AnalysisStatus.php) for the lifecycle, because
queued → processing → done → failed is the same lifecycle and the frontend
already has that union generated.

**Answers are generated off the queue, not in the request.** A tool-calling run
takes several Azure round trips and can block up to `ai.azure_block_cap_seconds`
on the outbound throttle alone, which is far longer than an HTTP request should
live. [AnswerRunQuestionJob](app/Jobs/AI/AnswerRunQuestionJob.php) runs on the
same `ai` queue and supervisor as the narrators; the client polls the thread.

**A summary-state run gets a smaller toolbox.** The summary-first ingest means an
un-opened run has no splits, zones, laps or terrain. Rather than offer tools that
answer `{}`, [the toolbox drops
them](app/Services/AI/Narrators/RunQuestionNarrator.php#L123) and keeps the run's
own summary numbers plus the history reads. Opening a run queues the detail
fetch, so a question asked in that window answers from the smaller set and a
later one answers from the full set. Neither ever announces what it could not
see — that is a persona rule ([[temari-keeps-score-persona]]).

**Suggestions are derived from the run, not from a fixed list.**
[`RunQuestionSeeds::for()`](app/Services/AI/RunQuestion/RunQuestionSeeds.php#L45)
detects which angles this run's stored numbers actually support and offers at
most four. A run with no streams collapses to the one question the always-present
history reads can answer.

**The demo is answered rule-based, never billed.**
[`RunQuestionController::store()`](app/Http/Controllers/Api/RunQuestionController.php#L63)
branches on `AnalysisService::shouldServeRuleBased` (`is_demo`) before anything
that could dispatch, and serves
[RuleBasedRunAnswer](app/Services/AI/RunQuestion/RuleBasedRunAnswer.php) — the
same stance, and the same `is_demo` key, as
[[demo-triggers-served-rule-based]].

**The per-user limit is a rate limit, not a cost cap.**
[`ai.run_question_rate_limit_per_minute`](config/ai.php#L27) (default 4) sits
below the analysis-trigger limit, because every accepted question is a real agent
run where a trigger usually collapses to a no-op on an already-Done row. App-wide
spend stays the `daily_cost_ceiling`'s job
([[idempotent-dispatch-cost-ceiling]]); there is deliberately no per-user cost
cap.

## Consequences

- **Enables:** a genuinely scoped Q&A that cannot be widened by phrasing, with
  metering, the content-filter retry, the truncation retry, the agent budget and
  the exception taxonomy all inherited from `StructuredChatCaller` unchanged. The
  `run_question` kind shows up on `/ai-usage` for free ([[ai-usage]]).
- **Costs:** a second AI storage shape to keep in mind. [[ai-pipeline]]'s "every
  piece of AI copy is an `ai_analyses` row" is no longer true, and the
  self-heal / dead-letter machinery ([[bounded-self-heal-and-dead-letter]]) does
  **not** cover questions — a failed question is re-asked, not re-armed.
- **Gotchas:** a question is billed even when the answer is short, because the
  tool reads happen before the model knows how short the answer will be. The rate
  limit is the only per-user bound on that.

## See also

- [[run-qa]] — the feature walkthrough
- [[narration-agents-on-openai-php]] — the agent loop and the bound-at-construction tool contract this reuses
- [[demo-triggers-served-rule-based]] — the demo billing stance this follows
- [[ai-pipeline]] — the row model this deliberately does not use
