---
title: Architecture — Map of Content
description: Hub for subsystem and cross-cutting architecture notes
tags: [architecture, moc]
status: living
reviewed: 2026-07-07
---

# Architecture

How the system is built. Start from [[DESIGN]] for the big picture, then drill in here.

## Architecture patterns

Cross-cutting patterns that appear across subsystems:

- **Idempotent dispatch** — every scheduled command, job, and webhook handler holds a lock or upsert guard so re-runs are safe. See [[idempotent-dispatch-cost-ceiling]], [[run-ingest-pipeline]].
- **Best-effort non-blocking augmentations** — weather, geo, and AI narration all fail silently without stranding the parent activity. No augmentation can block ingest.
- **Per-block state machine** — every AI narration block is a first-class row with a status lifecycle (pending→queued→processing→done/failed) and independent retry. No global "mode darurat" flag. See [[ai-pipeline]], [[bounded-self-heal-and-dead-letter]].
- **Separated metering** — cost/sync logs live on a separate `analytics` DB connection that survives `migrate:fresh`. See [[analytics-db]].
- **Transactional ingest** — the Strava-to-card path is wrapped in a DB transaction so a partial import cannot leave inconsistent state. See [[run-ingest-pipeline]].
- **Converged lookback** — EWMA-based metrics (CTL/ATL) use a bounded-but-converged lookback window instead of full history or a naive rolling window, giving correct values at O(year) cost. See [[training-load-metrics]].
- **Dawn-shift, light-mode only** — surface tints drift by time of day via `data-time-of-day` attribute; no dark mode, no `*-dark` tokens. See [[frontend-architecture]], [[design-tokens]].

## Notes

_Pipelines & metrics_
- [[run-ingest-pipeline]] — Strava sync → ActivityPipeline → metrics → transactional story layer
- [[stream-analysis]] — raw streams → `stream_summary` (HR zones, splits, decoupling, cadence)
- [[training-load-metrics]] — Edwards TRIMP, CTL/ATL EWMA, strain/monotony/form, backdated propagation

_AI narration_
- [[ai-pipeline]] — narrator → job → Analysis row; cadence; group/row jobs; chaining; cost ceiling; rule-based fallback
- [[ai-narration-internals]] — context builders (prompt signals) + rule-based fallback mechanics

_External integrations_
- [[strava-client]] — circuit breaker state machine, rate buckets, token refresh
- [[geo-reverse-geocoding]] — Nominatim resolver, cache, 1 req/s lock
- [[weather-integration]] — Open-Meteo forecast/archive routing, cache TTLs, rain threshold

_Data & runtime_
- [[data-model]] — core models + relationships
- [[analytics-db]] — the second DB connection, why, test rebinding
- [[frontend-architecture]] — Inertia controller → page → component, shared props, middleware
- [[deployment]] — FrankenPHP+Octane, Cloudflare tunnel, CI/CD, rollback, Redis partitioning

See also: [[design-tokens]], [[voice-and-tone]].
