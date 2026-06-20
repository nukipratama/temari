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

- [[ai-pipeline]] — narrator → job → Analysis row; cadence; group/row jobs; chaining; cost ceiling; rule-based fallback
- [[run-ingest-pipeline]] — Strava sync → ActivityPipeline → metrics → transactional story layer
- [[data-model]] — core models + relationships
- [[deployment]] — FrankenPHP+Octane, Cloudflare tunnel, CI/CD, rollback, Redis partitioning
- [[analytics-db]] — the second DB connection, why, test rebinding

See also: [[design-tokens]], [[voice-and-tone]].
