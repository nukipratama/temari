---
title: Scheduled state recovers after maintenance without replaying stale notifications
description: Durable cursors and bounded continuation work repair state that a skipped scheduler minute could otherwise lose, while time-sensitive notifications remain intentionally unreplayed.
tags: [decision, architecture, scheduler]
status: accepted
reviewed: 2026-09-22
code_refs:
  - app/Services/Gamification/StreakSettlementService.php
  - app/Services/Run/Trend/ScheduledTrendSnapshotRecovery.php
  - app/Actions/AI/KickoffCatchUp.php
  - app/Console/Commands/Gamification/SettleStreakTokensCommand.php
  - app/Console/Commands/Run/TrendSnapshotCommand.php
---

# Scheduled state recovers after maintenance without replaying stale notifications

## Context

Maintenance mode pauses every scheduled command except the liveness heartbeat. Most commands are safe because they scan durable backlog or calculate from the current period when they next run. Three paths also have deterministic writes whose scheduled minute used to be the only trigger: weekly streak settlement, closed-day trend snapshots, and the daily readiness clamp record.

## Decision

- `streak:settle` stores `users.streak_settled_through`. A null cursor causes a chronological rebuild of available weekly history, replacing derived rest-token outcomes atomically per athlete. Later recovery is bounded and self-dispatches until the cursor reaches the latest closed week. Weekly recap creation and filling remain gated while any athlete is behind.
- `trend:snapshot-daily` stores `users.trend_snapshots_scheduled_through`. The default command queues closed-date recovery in 365-day chunks through yesterday; an explicit `--days=N` remains a cursor-neutral focused repair. Scheduled recovery does not emit an ingest-origin trend event; the normal 06:00 fingerprint sweep decides whether narration changes.
- `ai:catch-up` keeps kickoff creation and filling separate, but also runs the deterministic current-day readiness-clamp recorder and stages its clamp voice under dispatch suppression. It never reconstructs past-day guidance.
- Missed reminders, races, morning pushes, and digests are not replayed after their useful window.

This note supersedes only the “nothing else” side-effect sentence in [[kickoff-catch-up-is-upsert-only]]; that ADR remains the source of truth for row creation, suppression, and self-heal ownership.

## Failure and rollout

The migration is additive and leaves the new cursors null. On the first deploy, run `./vendor/bin/sail artisan streak:settle` once after the migration while queue workers are healthy; this starts the bounded historical chunks before the next Monday window instead of concentrating the first catch-up burst at 00:00. The command is idempotent, and the next normal Monday remains a safe fallback if the operator run is skipped.

Each cursor update and token replacement is transactional per athlete. A failed job leaves the athlete behind and keeps the global recap gate closed. Trend and streak continuation jobs are idempotent and retryable; recovery performs no Strava or LLM call.
