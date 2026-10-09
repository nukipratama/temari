---
title: Ask about this run
description: The scoped per-run Q&A — suggested questions derived from the run's own data, a multi-turn conversation bound to that single activity, follow-ups, a per-run daily cap, and the persisted thread.
tags: [feature, ai]
status: living
reviewed: 2026-10-09
code_refs:
  - app/Http/Controllers/Api/RunQuestionController.php
  - app/Http/Requests/AskRunQuestionRequest.php
  - app/Http/Resources/RunQuestionResource.php
  - app/Services/AI/Narrators/RunQuestionNarrator.php
  - app/Services/AI/Agent/Tools/GetThreadTool.php
  - app/Services/AI/RunQuestion/RunQuestionSeeds.php
  - app/Services/AI/RunQuestion/RunQuestionTopic.php
  - app/Services/AI/RunQuestion/RuleBasedRunAnswer.php
  - app/Jobs/AI/AnswerRunQuestionJob.php
  - app/Models/AI/RunQuestion.php
  - routes/web.php
  - config/ai.php
  - resources/js/components/run/AskAboutRun.tsx
  - resources/js/hooks/useRunQuestions.ts
---

# Ask about this run

A conversation about one run. The toolbox behind it is bound to a single
activity, so the boundary is structural rather than a prompt rule; follow-ups can
build on earlier answers, but never reach past this run. See
[[scoped-run-qa-not-an-analysis-row]] for why it is shaped this way,
[[run-qa-is-a-conversation-about-one-run]] for why it became multi-turn, and
[[run-detail]] for the page it belongs to.

## The two endpoints

Both live in [RunQuestionController](app/Http/Controllers/Api/RunQuestionController.php)
and are registered in [routes/web.php](routes/web.php) behind the normal
auth group.

- `GET /api/activities/{activity}/questions` — this run's thread (oldest first),
  the `suggestions` this run's data supports, and `at_run_cap`. Unthrottled: it
  is what the client polls while an answer is generating.
- `POST /api/activities/{activity}/questions` — ask. Returns `201` with the row
  in its `queued` state; the answer arrives on a later `GET`. Throttled by the
  `run-question` limiter ([AppServiceProvider](app/Providers/AppServiceProvider.php),
  configured at [config/ai.php](config/ai.php)), and capped per run per day
  (below).

Ownership is checked against the authenticated user on both
([`ownedRun`](app/Http/Controllers/Api/RunQuestionController.php)); another
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
(visible on [[narration-devtools]]).

The [toolbox](app/Services/AI/Narrators/RunQuestionNarrator.php) is the run
insight set minus the claim-shaping bits, plus `get_thread`, and shrinks to the
run summary, `get_thread` and the history reads when the activity is still
`summary` state. The full agent
mechanics are in [[ai-narration-internals]] and [[narration-agents-on-openai-php]].

### The thread

[GetThreadTool](app/Services/AI/Agent/Tools/GetThreadTool.php) is how a follow-up
knows what came before. It is bound to this activity, its owner and the question
being answered, and returns the earlier `done` exchanges on this run, oldest
first, capped to the most recent six. The prompt's THREAD section tells the model
to call it when the question refers back ("why", "that", "what about…"); a first
question never needs it. Like every tool it takes no arguments, so a forged call
naming another run or user still reads this run's thread.

### Follow-ups

The structured output carries `follow_ups` beside `answer`: zero to two short
questions this run's data can answer that nobody asked yet. They are stored on
the row (`follow_ups`, nullable json) and exposed by the resource. A rule-based
answer offers the run's suggested questions nobody has asked yet instead
([`RunQuestionSeeds::unasked`](app/Services/AI/RunQuestion/RunQuestionSeeds.php)).

### Answered once

Each question is answered exactly once. The job takes the row with one
conditional update that stamps a fresh `claim_token` and `claimed_at`, and every
later write is fenced on that token. A first delivery never takes a live claim,
a queue retry takes over from its dead predecessor, and any delivery may reclaim
a claim older than the lease, which is the queue's `retry_after`. A duplicate
delivery never reaches the narrator, and a finisher that was taken over cannot
overwrite the row.

Failure is per-question and terminal: a failed question is marked `failed` with
its error and the user asks again. There is no self-heal sweep for questions,
unlike narration rows ([[bounded-self-heal-and-dead-letter]]).

## The per-run daily cap

One athlete may ask `ai.run_question_daily_cap_per_run` ([config/ai.php](config/ai.php))
(default 10) questions about one run per local day, on top of the per-minute
limit. [`store()`](app/Http/Controllers/Api/RunQuestionController.php) checks
it before the cost-ceiling and pause branches, so past it there is no agent run,
no rule-based answer and no row: the response is `429` with
`{"error": "run_cap"}`. Every row counts, including a failed one. The demo is
exempt because it never bills.

## The demo never bills

A demo account's question is answered from this run's own stored numbers by
[RuleBasedRunAnswer](app/Services/AI/RunQuestion/RuleBasedRunAnswer.php) and
marked `done` in the same request — no job, no Azure call. It answers the
suggested questions directly and falls back to the run's headline reading for
free text. Deterministic, so re-asking returns the same words. Same stance as
[[demo-triggers-served-rule-based]].

The demo login is public and the account is shared, so a demo question is never
stored: the `201` carries the answer with `id: null`, its follow-ups come from the
seeded questions plus the current one, and the panel keeps it in local state under
a client-side id until the page reloads. `index()` lists only the exchanges
[DemoRunSeeder::seededExchanges](database/seeders/Demo/DemoRunSeeder.php) writes,
matched on question and answer, so no visitor's text reaches the next visitor. Real
athletes keep every row, including the rule-based ones written past the cost
ceiling.

## The panel

[AskAboutRun](../../resources/js/components/run/AskAboutRun.tsx) renders this on
the run page, directly under the promoted Past You band ([[run-detail]]).
Because answers land later, it is not a request/response form:
[useRunQuestions](../../resources/js/hooks/useRunQuestions.ts) appends the
`201` row in its `queued` state, polls `index()` while anything is unsettled,
merges each read into the thread by row id (skipping a read older than one
already applied), so a slow read can never erase a just-asked question or take
a settled answer back to pending, and stops after a bounded number of polls into a "still working" state with a
manual re-check, so a stuck answer degrades into a visible wait rather than an
endless spinner or a lie.

The thread reads as an **interview transcript**, even though it is multi-turn.
Each entry sets the question behind a leading accent rule, muted and a size down,
with the answer beneath it in the `.narration` prose register — the separation is
size, colour and rule, never font style, and never bubbles, avatars or speaker
alignment. The invitation line and the starting points are **cold-start
affordances**: once the thread has an entry they retire, so what Temari already
said sits at the top of the panel rather than under a standing preamble. From
then on the **latest** settled answer carries a "keep going" row of its
follow-ups, which fill and send exactly like the starting points; older answers
never show theirs, and nothing shows while the newest question is pending.

Each refusal gets its own line: the throttle's `429` says the asking is too fast
without quoting a number the env can change, the `run_cap` `429` says that's
plenty on this run for today (again no number) and disables the box and the
chips, which `at_run_cap` keeps disabled across a reload, the `409` says generation is paused and
that nothing was sent, and a `422` asks for a rephrase. A `failed` row offers
to refill the box, matching the terminal-failure model above rather than
implying a retry that does not exist.

The panel is the only place a summary-state run is announced: the seeds already
collapse to `Baseline` on their own, but the smaller toolbox is said out loud
in the UI rather than left for the reader to infer from a thinner answer. It is
a live caveat on every answer, not empty-state copy, so unlike the invitation
above it stays put once the thread has entries.

## Storage

One [RunQuestion](app/Models/AI/RunQuestion.php) row per exchange in
`run_questions`, cascading with both the user and the activity. Status reuses
[AnalysisStatus](app/Services/AI/AnalysisStatus.php); the payload the client sees
is [RunQuestionResource](app/Http/Resources/RunQuestionResource.php).
