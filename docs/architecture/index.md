---
title: Architecture — Map of Content
description: Hub for subsystem and cross-cutting architecture notes
tags: [architecture, moc]
status: living
reviewed: 2026-06-20
---

# Architecture

How the system is built. Start from [[DESIGN]] for the big picture, then drill in here.

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
