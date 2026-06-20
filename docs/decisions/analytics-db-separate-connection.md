---
title: Metering on a separate `analytics` DB connection
description: Token-usage and Strava-sync metering live in their own schema so migrate:fresh can't wipe cost history
tags: [decision, data]
status: accepted
reviewed: 2026-06-20
code_refs:
  - config/database.php
  - app/Models/AI/TokenUsage.php
  - app/Models/Analytics/StravaSyncLog.php
  - tests/TestCase.php
  - app/Providers/AppServiceProvider.php
  - database/migrations/analytics/2026_05_19_132139_create_ai_token_usages_table.php
---

# Metering on a separate `analytics` DB connection

**Status:** Accepted (documented 2026-06-20)

## Context

Metering rows — LLM token/cost history and the Strava sync audit log — accumulate value over the life of the app and have no foreign-key dependence on app entities. But the app DB gets `migrate:fresh`-ed during development and reseeds, which would wipe that history along with it. We needed metering to outlive app-schema rebuilds without standing up a second database server.

## Decision

We decided to keep metering tables in a **separate schema on the same MySQL server**, reached through a second Eloquent connection named `analytics` (`DB_ANALYTICS_DATABASE`, default `teman_lari_analytics`). It shares the `mysql` host and credentials but a distinct database name — see the [`analytics` connection in config/database.php](../../config/database.php).

The metering models pin themselves to it: [`TokenUsage` (`$connection = 'analytics'`)](../../app/Models/AI/TokenUsage.php) and [`StravaSyncLog`](../../app/Models/Analytics/StravaSyncLog.php). Their schema lives outside the default migration path under `database/migrations/analytics/` (e.g. [the `ai_token_usages` migration](../../database/migrations/analytics/2026_05_19_132139_create_ai_token_usages_table.php)) and runs in dev/prod via `--database=analytics --path=...`.

In **tests** the second schema is a needless cost, so the `analytics` connection is rebound to the single default test DB before `RefreshDatabase` boots, via [`setUpTraits()` in tests/TestCase.php](../../tests/TestCase.php), and listed in `$connectionsToTransact` so its writes roll back per test. The analytics migrations are loaded into the normal migrate run only under the testing environment, guarded in [`AppServiceProvider::boot()`](../../app/Providers/AppServiceProvider.php).

## Consequences

- Cost history survives `migrate:fresh` of the app DB — the whole point.
- Tests must transact **both** connections; a separate PDO is not wrapped by `RefreshDatabase` on its own, so `ai_token_usages` writes would leak across tests without `$connectionsToTransact`.
- **Gotcha — keep `RefreshDatabase` per-file, do not globalize the trait.** Lazy refresh leaks the analytics connection, and a global *eager* trait breaks the DB-less CI structure gate. The per-file boundary is load-bearing.

## See also

- [[analytics-db]]
- [[data-model]]
