---
title: Per-narrator Azure OpenAI deployment routing
description: Each narrator kind picks its Azure deployment via config/env; cheap mini by default, stronger model only for high-value kinds.
tags: [decision, ai]
status: accepted
reviewed: 2026-06-20
code_refs:
  - config/azure_openai.php
  - app/Services/AI/AzureOpenAIClient.php
---

# Per-narrator Azure OpenAI deployment routing

**Status:** Accepted (documented 2026-06-20)

## Context

Output tokens dominate LLM cost, and our narrator kinds span a wide value range: a one-line card flavor is not worth the same model as a monthly recap. Sending everything to the strongest deployment would be wasteful; sending everything to the cheap one would flatten the high-value narration. We wanted per-kind model selection without scattering model-choice logic through the call sites.

## Decision

We decided routing is **config/env-only**, with a per-kind deployment map and a single primary fallback.

In [config/azure_openai.php](config/azure_openai.php) the `narrators` array maps each narrator kind to a deployment name, and **every entry defaults to the general `AZURE_OPENAI_DEPLOYMENT`**. So an unset kind transparently uses the primary model; we only set a per-kind env var for the kinds we want to route elsewhere. In practice the primary is the cheap mini deployment, and only the high-value kinds (weekly/monthly recap, `pr_context`, `persona_summary`) get pointed at the stronger model. The exact mapping lives in config and is not duplicated here.

[AzureOpenAIClient::deploymentFor()](app/Services/AI/AzureOpenAIClient.php) reads `azure_openai.narrators.{kind}` and falls back to `azure_openai.deployment` when the kind is null/unmapped/empty. The resolved deployment is sent as the request body's `model` (Azure's OpenAI-compatible v1 surface selects by `model`, not URL path); host + API key are shared across all kinds.

There is **no per-call model logic** — call sites pass a `kind`, and the deployment is resolved from config alone.

## Consequences

- **Enables:** cost tuning per kind by editing env, with no code change or deploy of app logic; a sensible default (everything tracks the primary) so adding a narrator needs no routing work.
- **Costs:** routing changes require an env edit + service roll, not a runtime toggle. The mapping lives in two places conceptually (config keys here, env values in the host `.env`), so a mis-set env silently routes a kind to the wrong deployment.
- **Gotchas:** an empty per-kind value falls through to the primary (the `?:` in `deploymentFor`), so a typo'd env var degrades to the default rather than erroring. Cost attribution keys off the *deployment name* recorded in `ai_token_usages.model`, so two kinds sharing one deployment are indistinguishable in the cost report.

## See also

- [[ai-pipeline]] — the narrator pipeline these deployments serve
