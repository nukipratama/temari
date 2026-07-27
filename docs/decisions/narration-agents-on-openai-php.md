---
title: Narration agents stay on openai-php, bounded per block
description: Why the tool-calling narrator loop was built on the existing openai-php seam instead of migrating to laravel/ai, and what bounds one agent run.
tags: [decision, ai]
status: accepted
reviewed: 2026-07-27
code_refs:
  - app/Services/AI/StructuredChatCaller.php
  - app/Services/AI/Agent/AgentBudget.php
  - app/Services/AI/Agent/AgentToolbox.php
  - app/Services/AI/Agent/AgentTool.php
  - config/ai.php
  - config/horizon.php
---

# Narration agents stay on openai-php, bounded per block

**Status:** Accepted (documented 2026-07-27)

## Context

Narrators used to receive everything they might need as one pre-computed context object — the run-insight prompt alone carried around thirty fields, most of them null on any given run. Letting the model fetch what it decides it needs means it can reach further (history, comparisons) without every prompt paying for every signal.

Doing that needs tool calling, and the plan was to migrate the LLM layer to `laravel/ai` first, for tidiness and provider portability.

## Decision

**We stay on `openai-php`.** A spike against the installed package asked seven questions; six passed, and the one that failed was the gate.

`Gateway/OpenAi/Concerns/ParsesTextResponses.php`, which the Azure provider reuses, maps `'incomplete' => FinishReason::Length` unconditionally and never reads `incomplete_details.reason`; `parseTextResponse` then discards the raw payload, so `Meta` keeps only provider, model and citations. Azure's **output-side content filter** — an HTTP 200 marked `incomplete` with reason `content_filter` and an empty body — would arrive indistinguishable from ordinary token truncation. The caller would apply the token-bump retry, get filtered again, and fail the block — the same bug we fixed once already, returning as a randomly flaky narrator.

The migration was never a prerequisite for tools. `Tool/FunctionTool.php`, `Output/OutputFunctionToolCall.php`, `Input/FunctionToolCallOutput.php` and `CreateResponseIncompleteDetails.php` all exist on the current client, so the agent loop is buildable with the filter detection at [StructuredChatCaller::isOutputContentFiltered()](app/Services/AI/StructuredChatCaller.php) intact.

The loop lives in the existing seam rather than beside it ([`converse()`](app/Services/AI/StructuredChatCaller.php)), so the content-filter strip-retry, the truncation retry, the exception taxonomy, the config circuit breaker and metering apply to an agent run exactly as they do to a one-shot call. A narrator without a toolbox still issues exactly one request.

Three properties are deliberate:

- **Scoping is structural, not validated.** A tool is constructed already bound to its subject and declares an argument-free schema ([ActivityTool](app/Services/AI/Agent/Tools/ActivityTool.php)), so there is no id a model could pass to reach another user's data. Nothing has to check.
- **Nothing a model gets wrong fails the block.** An invented tool name, malformed arguments, or a throwing read all return an error payload and another turn ([AgentToolbox::invoke()](app/Services/AI/Agent/AgentToolbox.php)).
- **One block is bounded twice.** The daily cost ceiling is a *dispatch-time* gate, read once before the job is queued and never again, so it cannot stop a loop that keeps deciding it needs one more read. [AgentBudget](app/Services/AI/Agent/AgentBudget.php) caps steps and tokens per block, and a run that hits either ceiling is forbidden further tools and made to answer from what it has, rather than erroring. Tokens accumulate across every step *and* across a retried attempt, because those tokens were billed.

## Consequences

- Metering is now the whole run's, not the final turn's — one `ai_token_usages` row per block, summed across steps. The 13 coarse `kind` literals `/ai-usage` groups on are unchanged.
- Azure calls remain invisible to Pulse, since `openai-php` uses Guzzle directly rather than Laravel's HTTP client. The `#/openai/deployments/#` threshold in `config/pulse.php` could never have matched anyway (the deployment travels in the request body, not the URL), and is removed as misleading config rather than fixed.
- The same reason keeps `Http::preventStrayRequests()` unable to catch these calls in tests; the `ClientFake` binding in `tests/Pest.php` remains the net.
- Narration moved to its own queue and Horizon supervisor with a 300 s timeout — several round trips do not fit the 60 s the rest of the queue lives by. See [[ai-pipeline]].
- Output varies more: the same run re-narrated may read differently, since the model chooses what to look at. That is on-brand, and the idempotency guard still prevents double-billing on retries.
- Provider portability was the migration's real prize and we did not buy it. Revisit if `laravel/ai` starts surfacing `incomplete_details.reason` on a successful response.

## See also

- [[ai-pipeline]] — the row lifecycle and the dispatch-time cost guards.
- [[ai-narration-internals]] — what a narrator hands the model, and what it now fetches.
- [[azure-openai-routing]] — per-narrator deployment routing, unchanged by this.
