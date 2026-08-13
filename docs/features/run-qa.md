---
title: Ask about this run
description: The scoped per-run Q&A — suggested questions derived from the run's own data, an agent answer bound to that single activity, and the persisted thread.
tags: [feature, ai]
status: living
reviewed: 2026-08-13
code_refs:
  - app/Http/Controllers/Api/RunQuestionController.php
  - app/Http/Requests/AskRunQuestionRequest.php
  - app/Http/Resources/RunQuestionResource.php
  - app/Services/AI/Narrators/RunQuestionNarrator.php
  - app/Services/AI/RunQuestion/RunQuestionSeeds.php
  - app/Services/AI/RunQuestion/RunQuestionTopic.php
  - app/Services/AI/RunQuestion/RuleBasedRunAnswer.php
  - app/Jobs/AI/AnswerRunQuestionJob.php
  - app/Models/AI/RunQuestion.php
  - routes/web.php
  - resources/js/components/run/AskAboutRun.tsx
  - resources/js/hooks/useRunQuestions.ts
---

# Ask about this run

One run, one question, one answer. Not a chat — the toolbox behind it is bound to
a single activity, so the boundary is structural rather than a prompt rule. See
[[scoped-run-qa-not-an-analysis-row]] for why it is shaped this way, and
[[run-detail]] for the page it belongs to.

## The two endpoints

Both live in [RunQuestionController](app/Http/Controllers/Api/RunQuestionController.php)
and are registered in [routes/web.php](routes/web.php#L215) behind the normal
auth group.

- `GET /api/activities/{activity}/questions` — this run's thread (oldest first)
  plus the `suggestions` this run's data supports. Unthrottled: it is what the
  client polls while an answer is generating.
- `POST /api/activities/{activity}/questions` — ask. Returns `201` with the row
  in its `queued` state; the answer arrives on a later `GET`. Throttled by the
  `run-question` limiter ([AppServiceProvider](app/Providers/AppServiceProvider.php#L109),
  configured at [config/ai.php](config/ai.php#L27)).

Ownership is checked against the authenticated user on both
([`ownedRun`](app/Http/Controllers/Api/RunQuestionController.php#L107)); another
user's run is a `403`, not a `404`, matching the analysis endpoints.

## Suggested questions come off the run

[RunQuestionSeeds](app/Services/AI/RunQuestion/RunQuestionSeeds.php) walks the
[RunQuestionTopic](app/Services/AI/RunQuestion/RunQuestionTopic.php) cases and
keeps only the ones this run carries a real reading for — HR drift above the
noise floor, a decoupling figure at all, an actual negative split, enough splits
to compare, a Z3+ share worth asking about, a hot day, a steep grade. The floors
are the constants at the top of that file; don't restate them here. `Baseline`
always detects, so a run with no streams still offers one honest question.

At most four are returned, so suggestions stay suggestions rather than a menu.
The user is free to type anything else — the seeds are a starting point, never
the accepted set.

## Answering

[AnswerRunQuestionJob](app/Jobs/AI/AnswerRunQuestionJob.php) runs on the `ai`
queue and calls
[RunQuestionNarrator](app/Services/AI/Narrators/RunQuestionNarrator.php), which
goes through `StructuredChatCaller` like every other narrator — so the persona,
the agent budget, the content-filter retry, the exception taxonomy and the
`ai_token_usages` metering all apply unchanged, under the `run_question` kind
(visible on [[ai-usage]]).

The [toolbox](app/Services/AI/Narrators/RunQuestionNarrator.php#L113) is the run
insight set minus the claim-shaping bits, and shrinks to the run summary plus the
three history reads when the activity is still `summary` state. The full agent
mechanics are in [[ai-narration-internals]] and [[narration-agents-on-openai-php]].

Failure is per-question and terminal: a failed question is marked `failed` with
its error and the user asks again. There is no self-heal sweep for questions,
unlike narration rows ([[bounded-self-heal-and-dead-letter]]).

## The demo never bills

A demo account's question is answered from this run's own stored numbers by
[RuleBasedRunAnswer](app/Services/AI/RunQuestion/RuleBasedRunAnswer.php) and
marked `done` in the same request — no job, no Azure call. It answers the
suggested questions directly and falls back to the run's headline reading for
free text. Deterministic, so re-asking returns the same words. Same stance as
[[demo-triggers-served-rule-based]].

## The panel

[AskAboutRun](../../resources/js/components/run/AskAboutRun.tsx) renders this on
the run page, directly under the promoted Past You band ([[run-detail]]).
Because answers land later, it is not a request/response form:
[useRunQuestions](../../resources/js/hooks/useRunQuestions.ts) appends the
`201` row in its `queued` state, polls `index()` while anything is unsettled,
and stops after a bounded number of polls into a "still working" state with a
manual re-check, so a stuck answer degrades into a visible wait rather than an
endless spinner or a lie.

Each refusal gets its own line: the `429` says the asking is too fast without
quoting a number the env can change, the `409` says generation is paused and
that nothing was sent, and a `422` asks for a rephrase. A `failed` row offers
to refill the box, matching the terminal-failure model above rather than
implying a retry that does not exist. Suggestions already asked in this thread
are dropped so they stay starting points.

The panel is the only place a summary-state run is announced: the seeds already
collapse to `Baseline` on their own, but the smaller toolbox is said out loud
in the UI rather than left for the reader to infer from a thinner answer.

## Storage

One [RunQuestion](app/Models/AI/RunQuestion.php) row per exchange in
`run_questions`, cascading with both the user and the activity. Status reuses
[AnalysisStatus](app/Services/AI/AnalysisStatus.php); the payload the client sees
is [RunQuestionResource](app/Http/Resources/RunQuestionResource.php).
