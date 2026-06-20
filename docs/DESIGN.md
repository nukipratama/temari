---
title: teman-lari — System Design
description: Apex overview of what teman-lari is, its design principles, subsystems, and data lifecycle
tags: [architecture, moc]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/Run/Ingest/ActivityPipeline.php
  - app/Services/AI/AnalysisService.php
  - app/Models/AI/Analysis.php
  - app/Services/Strava/StravaClient.php
  - routes/console.php
  - config/database.php
---

# teman-lari — System Design

The home/hub for the knowledge base. README explains how to *operate* the app; this explains how it's *built and why*. For detail, follow the section maps: [[architecture/index|Architecture]] · [[decisions/index|Decisions (ADRs)]] · [[features/index|Features]].

## What it is

A personal running companion: it connects to Strava, ingests each run, computes proper running metrics, turns runs into collectible gamified cards, and narrates them with an AI mascot ("Temari") in an Indonesian-first voice. It is **not** a Strava clone — the gimmick (cards, vibes, mascot narration) is the point, layered on a correct run-tracker core.

## Core design principles

- **Cost-predictable LLM.** AI narration never auto-retries; failed blocks wait for a manual re-trigger. Dispatch is idempotent and windowed so the same recap is never re-billed. See [[decisions/index|the ADRs]] and [app/Services/AI/AnalysisService.php](app/Services/AI/AnalysisService.php).
- **Indonesian-first voice, English only for running terms.** All UI/vibes/copy in Bahasa Indonesia; domain words (pace, splits, HR) stay English. Rules in [[voice-and-tone]].
- **Light-mode only.** No `.dark`, no `*-dark` tokens. Tokens in [[design-tokens]].
- **Metering survives app resets.** A separate `analytics` DB connection holds token-usage/metering so it outlives `migrate:fresh`. See [config/database.php](config/database.php).
- **Homelab runtime.** FrankenPHP + Octane behind a Cloudflare tunnel; deploys via GitHub Actions. Dev mirrors prod via Sail.

## Major subsystems

Backend logic is split by domain under `app/Services/`:

- **Ingestion** — Strava sync → detail/streams/weather fetch → metrics → run cards + story, all idempotent and transactional. Entry: [app/Services/Run/Ingest/ActivityPipeline.php](app/Services/Run/Ingest/ActivityPipeline.php); Strava access via [app/Services/Strava/StravaClient.php](app/Services/Strava/StravaClient.php) (circuit breaker + per-client rate limit). See [[architecture/index|Architecture → run-ingest-pipeline]].
- **AI narration** — one narrator per analysis type → queued job → an [Analysis](app/Models/AI/Analysis.php) row (pending/queued/processing/done/failed). Orchestrated by [app/Services/AI/AnalysisService.php](app/Services/AI/AnalysisService.php). See [[architecture/index|Architecture → ai-pipeline]].
- **Gamification** — milestones, personal records, unlocks, card rarities. Under `app/Services/Gamification/` and `app/Services/Run/Story/`.
- **Geo / Weather** — best-effort reverse-geocode (Nominatim) and weather snapshot (Open-Meteo) augment each run. Under `app/Services/Geo/` and `app/Services/Weather/`.
- **Frontend** — Inertia 2 + React 19 pages in `resources/js/pages/`, rendered by controllers in `app/Http/Controllers/`. See [[features/index|Features]].

## Data lifecycle (high level)

1. User connects Strava (OAuth). Scheduled `strava:sync` + webhooks pull new activity stubs — see [routes/console.php](routes/console.php).
2. The ingest pipeline fetches detail, computes metrics (HR zones, pace, training load, PRs), reverse-geocodes, attaches weather, and atomically writes the run card + story layer.
3. AI narrators generate per-block narratives as Analysis rows; cadence-based scheduled commands fire weekly/monthly/daily recaps once their window closes.
4. Inertia controllers render React pages that read the activities, cards, records, and Analysis content.

## Where the conventions live

Design tokens, voice & tone, the AI narrator pipeline detail, the 1:1 test convention, and the Sail toolchain live in the `teman-lari` skill (`.claude/skills/teman-lari/`) and in [[design-tokens]] / [[voice-and-tone]]. This knowledge base narrates and links to those — it does not duplicate them.
