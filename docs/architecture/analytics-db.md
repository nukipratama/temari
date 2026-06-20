---
title: Analytics DB connection
description: The second `analytics` MySQL connection that survives migrate:fresh, where its models/migrations live, and how tests rebind it.
tags: [architecture, data]
status: living
reviewed: 2026-06-20
code_refs:
  - config/database.php
  - app/Models/AI/TokenUsage.php
  - app/Models/Analytics/StravaSyncLog.php
  - database/migrations/analytics/2026_05_19_132139_create_ai_token_usages_table.php
  - app/Providers/AppServiceProvider.php
  - tests/TestCase.php
  - .github/workflows/ci.yml
---

# Analytics DB connection

Metering/audit tables (AI cost history, Strava sync logs) live in a **separate MySQL
schema** so a `migrate:fresh` of the app database can't wipe them. It is the same
MySQL server and the same credentials as the default `mysql` connection, just a
different database name. This note is the first stop before touching anything that
writes there.

## How the connection is defined

The `analytics` connection block in [config/database.php](config/database.php) is a
clone of the `mysql` block with one difference: its `database` reads
`env('DB_ANALYTICS_DATABASE', 'teman_lari_analytics')` instead of `DB_DATABASE`.
Host, port, `DB_USERNAME`, `DB_PASSWORD`, charset and SSL options are all shared, so
it points at a sibling schema on the same server. It is **not** the default — every
read/write must name it explicitly.

## Which models use it

Two Eloquent models pin themselves to it via `protected $connection = 'analytics'`:

- [TokenUsage](app/Models/AI/TokenUsage.php) — table `ai_token_usages`, the per-call
  LLM token/cost ledger (`$timestamps = false`, only `created_at`).
- [StravaSyncLog](app/Models/Analytics/StravaSyncLog.php) — table `strava_sync_logs`;
  write through its `StravaSyncLog::log()` factory method, not raw `create()` scattered
  about.

Read-side services skip Eloquent and go straight through the query builder with
`DB::connection('analytics')` — see `LlmCostCalculator`, `TokenUsageReport`, and the
`StravaHealth` Pulse card. If you add a query, use the same explicit connection name;
a bare query will hit the app DB where these tables don't exist.

## Where migrations live and how they run

Analytics migrations live **outside** the default path, in
`database/migrations/analytics/` (e.g.
[create_ai_token_usages_table](database/migrations/analytics/2026_05_19_132139_create_ai_token_usages_table.php)).
They are kept out of the normal `migrate` run on purpose. In dev/prod you run them
against the analytics schema explicitly:

```
php artisan migrate --database=analytics --path=database/migrations/analytics --force
```

CI does exactly this as its own step in [ci.yml](.github/workflows/ci.yml). Note the
migrations themselves use `Schema::create(...)` (not `Schema::connection('analytics')`),
so the `--database=analytics` flag is what routes them to the right schema. There is no
cross-schema foreign key from these tables back to `users` (impossible across schemas),
so `user_id` is a bare nullable integer.

## How tests rebind it to the test DB

In tests there is only one database. [TestCase](tests/TestCase.php) folds the analytics
tables into the default test DB so they migrate and roll back transactionally:

1. `setUpTraits()` rewrites `database.connections.analytics.database` to equal the
   default test database (including the paratest per-process suffix), then
   `DB::purge('analytics')` drops the stale PDO so the new database name takes effect.
   This runs *after* ParallelTesting has switched the default connection.
2. `loadMigrationsFrom(database_path('migrations/analytics'))` in
   [AppServiceProvider](app/Providers/AppServiceProvider.php) `boot()` — guarded by
   `environment('testing')` — pulls the analytics migrations into the single
   RefreshDatabase migrate run.
3. `$connectionsToTransact = ['mysql', 'analytics']` tells RefreshDatabase to wrap the
   analytics PDO in a transaction too. Without it, `ai_token_usages` writes would commit
   and leak across tests since it's a separate PDO.

## See also

- [[data-model]]
- [[ai-pipeline]]
- [[analytics-db-separate-connection]] (ADR — not written yet)
