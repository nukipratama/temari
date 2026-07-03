---
title: Per-block manual retry, never auto-retry
description: Failed AI blocks are never auto-retried; retry is a per-block manual action, capped by cooldown + rate limit.
tags: [decision, ai]
status: accepted
reviewed: 2026-06-20
code_refs:
  - app/Jobs/AI/AnalyzeBaseJob.php
  - app/Models/AI/Analysis.php
  - app/Services/AI/AnalysisStatus.php
  - resources/js/components/temari/AnalysisStatus.tsx
  - config/ai.php
---

# Per-block manual retry, never auto-retry

**Status:** Accepted (documented 2026-06-20)

## Context

Every narration block is LLM-backed and bills tokens on each call. If a failed block were silently re-dispatched on a schedule, one upstream incident could fan out into a large, invisible bill — and a "global emergency mode" chip would hide which blocks actually failed. We needed a failure model where cost stays predictable and the per-block state is the single source of truth.

## Decision

We decided that **failed AI blocks are never auto-retried**. The queue retries a transient upstream failure only while a native `$tries` slot remains (`$tries = 3`, `$backoff = [10, 60]` on [AnalyzeBaseJob](app/Jobs/AI/AnalyzeBaseJob.php)); a `TransientUpstreamException` re-queues the row and releases the job, while a terminal `UnavailableException` ends the attempt. Once retries are exhausted the job lands in `failed_jobs` and the Analysis row is marked `failed` ([AnalysisStatus](app/Services/AI/AnalysisStatus.php) `Failed`). No scheduler re-fires it.

Retry from there is **manual only**, per block:

- the UI renders a "Coba lagi" button on a `failed` row and "Baca ulang" on a `done` row, via [AnalysisStatus.tsx](resources/js/components/temari/AnalysisStatus.tsx);
- a developer can re-dispatch the failed job from Horizon.

There is **no global "mode darurat" chip** — the row's own status drives the UI.

Two guards cap rapid re-fire. A per-block cooldown: once a `done` row is generated it opens a 15-minute Redis-backed window ([Cooldown](app/Support/Cooldown.php), started in `AnalysisService::markDone()`), reported as a `retry_after_seconds` countdown via [Analysis::cooldownRemaining()](app/Models/AI/Analysis.php) that the button honours as a disabled countdown state. And a per-user sliding-minute ceiling, `ai.rate_limit_per_minute` (default 8) in [config/ai.php](config/ai.php), catches rapid clicks across many blocks.

## Consequences

- **Enables:** predictable LLM spend — a failed block costs nothing until a human asks for it; and clear, localized failure UI (each block owns its state, no app-wide flag to reason about).
- **Costs:** a transient failure that exhausts `$tries` stays empty until someone clicks; there is no self-healing.
- **Gotchas:** a re-queued (transient) row is intentionally *not* shown as "Coba lagi", so a manual retry can't race a second LLM call mid-backoff. The cooldown applies only to `done` rows; a `failed` row can be retried immediately (subject to the per-user rate limit).

## See also

- [[ai-pipeline]] — the narrator/analysis pipeline this failure model sits in
- [[idempotent-dispatch-cost-ceiling]] — the dispatch-side cost guards
