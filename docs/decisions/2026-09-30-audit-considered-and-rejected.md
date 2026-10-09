---
title: Audit 2026-09 — options considered and rejected or deferred
description: The infrastructure, AI-cost, storage and tooling options the September 2026 audit measured and declined, each with its numbers and the trigger that reopens it.
tags: [decision, audit, infra]
status: accepted
reviewed: 2026-09-30
code_refs:
  - app/Models/ActivityStream.php
  - app/Services/Run/Ingest/ActivityPipeline.php
  - database/migrations/2026_05_11_081050_create_activity_streams_table.php
  - resources/js/hooks/useAnalysisTrigger.ts
  - resources/js/hooks/useRunQuestions.ts
  - resources/js/components/run/RunHydratingNotice.tsx
  - config/azure_openai.php
  - config/horizon.php
  - routes/console.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Services/AI/MaterialFingerprint.php
  - docker/Caddyfile
  - public/sw.js
  - app/Support/Config/AppConfig.php
  - app/Http/Middleware/SetInertiaEtag.php
  - package.json
  - eslint.config.js
  - vitest.config.ts
  - compose.yaml
  - compose.prod.yaml
  - .github/workflows/nightly-backup.yml
---

# Audit 2026-09 — options considered and rejected or deferred

**Status:** Accepted (decided 2026-09-30)

The September 2026 audit measured a set of changes the owner asked about, or that a reviewer would reasonably propose. Each one either has no payoff at today's scale or is blocked outside the repo. This note records the numbers behind every "no" so nobody re-investigates, and names the **revisit trigger** that turns each one back into work. Nothing here is a rule against the option forever; it is a rule against doing it before its trigger fires.

Scale at the time of writing: about four athletes, a 90 MB database, one shared 4-core host, roughly 30 LLM jobs a day.

## Stream storage: keep raw JSON in MySQL

**Considered:** compress `activity_streams.data`, or move it to another store.

`data` is Strava's raw `key_by_type` JSON (eight series, requested at `app/Services/Run/Ingest/ActivityPipeline.php:278`) in a `longText` column cast to `array` (`database/migrations/2026_05_11_081050_create_activity_streams_table.php:15`, `app/Models/ActivityStream.php:46`). It is written once at ingest and read only by four batch paths (single-run reread, whole-history recalibration, `splits:rebuild`, compare-recalibration). No page view or narrator reads it.

**Numbers.** 126 local rows average 254 KB raw, the largest is 1.35 MB (22,110 samples). `latlng` alone is 37% of the bytes. `gzcompress` level 6 gives 3.51x over the whole table, costs about 10 ms per row to compress at ingest and 0.66 ms per row to decompress, against 9 ms to `json_decode` the largest row. In prod the table is 81 MB of the 90 MB database across 481 rows; compressed it would be about 23 MB. Growth is what matters: the drain fetches streams for the full history ([[summary-first-ingest]], [[background-hydration-drain]]), and one athlete has been seen at about 900 runs. Ten athletes at about 900 runs of about 170 KB is roughly 1.5 GB raw and roughly 430 MB compressed.

**Verdict.** Do nothing today; the payoff is growth, not the current 81 MB. When it fires, compress in the application layer (a gzip-plus-JSON cast that recognises legacy plain JSON by its leading `{`, and a chunked convert command), not in the database: MySQL 8.4 has no column compression and `ROW_FORMAT=COMPRESSED` keeps compressed and uncompressed pages in the buffer pool.

Rejected outright, at any scale we can foresee:

- **MongoDB or another document store.** A third stateful service on a shared 4-core host to hold write-once blobs that one PHP process reads in batch. It also loses the cascade delete and the single-dump restore.
- **A time-series database.** Nothing queries samples by time range; every reader wants the whole run.
- **Object storage.** No S3 on the host, and local-disk files would need their own backup and restore path.
- **Binary delta packing.** Perhaps 8-10x, but a bespoke codec for a table only batch jobs read.

**Revisit when** the athlete count passes about 10 or `activity_streams` passes about 500 MB, whichever comes first.

## Narration status: keep bounded polling, no push channel

**Considered:** Laravel Reverb, server-sent events, or FrankenPHP's built-in Mercure instead of polling.

Four pollers exist and all are bounded. The Analysis poll registry shares one timer per reload-prop set across every block on the page, starts at 3 s and grows by 1.4x to 15 s, stops after 30 attempts and pauses while the tab is hidden (`resources/js/hooks/useAnalysisTrigger.ts:13`, `:96`, `:141`). Run Q&A polls every 3 s up to 40 times (`resources/js/hooks/useRunQuestions.ts:25`, `:28`), the hydrating-run notice every 8 s up to 30 times (`resources/js/components/run/RunHydratingNotice.tsx:9`), and the first-sync empty state every 7 s only while the sync is running. Each poll is a partial Inertia reload of lazily evaluated props, not a full render.

**Numbers.** Prod narration latency is 3-16 s per call (14-day average), so an in-flight block costs two to four partial reloads per open page and nothing while the tab is hidden or nothing is pending. With about 30 LLM calls a day across about four athletes, most fired from ingest while nobody is looking, that is on the order of 100 small requests a day. Push would remove those at the cost of an always-on process: Reverb is a new container (about 60-100 MB) plus a WebSocket through the tunnel and a client library in the entry chunk; SSE holds an Octane worker per open tab; Mercure needs a JWT secret, CSP `connect-src` changes and broadcast plumbing from every job that settles a row.

**Verdict.** Keep polling.

**Revisit when** Pulse's slow-request or request-count cards show the reload endpoints among the top entries, or the athlete count passes about 50, where open pages times in-flight blocks stops being negligible.

## AI cost: no Azure Batch, no model downshift, no response cache, no merged post-run call

Measured over 14 days of prod usage, priced with the rates in `config/azure_openai.php:58`: total LLM spend is $1.02 across 153 calls ($0.75 uncached input, $0.11 cached input, $0.16 output). The only lever that matters is uncached input (73% of spend); it is attacked by the prompt-diet and single-tool work, not by architecture.

**Azure Batch API (about 50% off, 24 h turnaround).** Every narrator is an agent loop that resends the conversation per tool step (`app/Services/AI/Agent/AgentLoop.php:63`); a batch request is single-turn, so each tool round trip would become another batch submission of up to 24 h. Everything plausibly batchable (weekly and monthly recaps plus the scheduled profile-voice and trend-read calls) is $0.237 of the $1.02, so the discount saves about $0.12 per 14 days, roughly $0.25 a month. The cost side is a second deployment, a submit-and-poll job type beside the per-block state machine, and recaps landing up to a day late. **Revisit only if** monthly LLM spend passes about $50, and then for single-turn kinds only.

**Smaller models.** Eight of twelve kinds already run on the mini deployment; the four on the stronger model (profile voice, weekly and monthly recap) were routed there deliberately ([[azure-openai-routing]]). Moving them all would have cost $0.116 instead of $0.288 over 14 days, about $0.37 a month, at the quality risk the owner already weighed.

**Response caching.** Outputs are per athlete and per data state, and `app/Services/AI/MaterialFingerprint.php` already skips re-narration when the material has not changed. Structured outputs are already strict JSON schema.

**One merged post-run call.** `app/Listeners/DispatchPostRunAnalysis.php` fans about five narrations out per run (run insight, post-run speech, card flavour, briefing, plan voice). Ingest-origin narration is 66 calls and $0.41 per 14 days (about five calls and $0.03 per run); a merged call might halve that, about $0.4 a month, but gives up per-block status and retry, per-kind model routing and per-narrator validators, which are the per-block state machine ([[ai-pipeline]]).

**Revisit when** the single-tool and prompt-diet work has landed and the post-run burst has been re-measured, and only if monthly spend has moved by an order of magnitude.

## Queue and scheduler topology: keep it

**Considered:** more Horizon supervisors, a different queue backend (SQS, RabbitMQ, a second Redis) or a separate worker host.

Two supervisors run today, `default` and `ai` (`config/horizon.php:216`, `:241`), plus one `schedule:work` container driving the entries in `routes/console.php`, all `onOneServer` and `withoutOverlapping`. Prod shows zero failed jobs in 30 days, about 30 LLM jobs a day, and Horizon at about 309 MiB. The `ai` supervisor's two processes cover the post-run burst of about five calls of 3-16 s. A new backend adds a service for no measured backlog, and a separate worker host is not available on a single homelab. The one waste found in this layer, CLI boot cost, is a separate fix.

**Revisit when** Horizon's wait-time metric for the `ai` queue exceeds the per-block polling budget (about seven minutes, the 30-attempt cap in `resources/js/hooks/useAnalysisTrigger.ts`).

## Search, edge caching, more HTTP caching, offline data, feature flags: no change

- **Search.** The UI has no search box and nothing queries with `LIKE`; the history feed paginates by week. **Revisit** if a runs list ever exceeds a few hundred items per athlete with a filter UI.
- **CDN or edge caching.** Cloudflare already fronts the tunnel and compresses at the edge. `/build/*` is content-hashed and served `immutable` for a year (`docker/Caddyfile:31`), and the whole build is 1.9 MB.
- **More HTTP caching.** The one route that is revisited with a large eager payload, `/activities/{activity}`, already sends a byte-hash ETag (`app/Http/Middleware/SetInertiaEtag.php`, [[frontend-architecture]]). Partial reloads are deliberately `no-store`. `/history` used to carry the alias and lost it when its payload moved behind `Inertia::defer()`.
- **Offline data.** The service worker caches only `/offline.html`, on purpose: every page is per-athlete and time-sensitive (`public/sw.js:3`).
- **A feature-flag library.** `AppConfig` is a durable, 60-second-cached runtime switch table (`app/Support/Config/AppConfig.php`), and there are about four athletes, so per-user percentage rollouts have nobody to roll out to. **Revisit** if the athlete count passes about 50.

## TypeScript 7: stay on 6

**Considered:** upgrading `typescript` to 7 now, or running both compilers side by side.

The blocker is `typescript-eslint`, which declares a peer range below TypeScript 6.1 (`package.json:44`, `:45`), so `typescript@7` fails to resolve. TypeScript 7.0 shipped without the programmatic API it needs; that API stabilises in 7.1 (iteration plan: https://github.com/microsoft/TypeScript/issues/63703, stable planned 2026-11-24). `eslint.config.js:6` uses the non-type-checked preset, so ESLint needs only the parser, but it still needs a release that accepts TypeScript 7.

**Numbers.** The full typecheck is 4.8 s on TypeScript 6 (3.7 s of it checking, 1,413 files, 70k lines), so a roughly 8-10x compiler saves about 4 s per gate or CI run. The side-by-side alias documented for the transition adds a second compiler to maintain for that. The tsconfig itself would migrate cleanly.

**Verdict.** Do nothing until the parser catches up.

**Revisit when** `typescript-eslint` publishes a release whose peer range includes 7.x. Tracking: https://github.com/typescript-eslint/typescript-eslint/issues/10940. Then bump `typescript` and `typescript-eslint` together. Until then, keep TypeScript majors out of automated update PRs.

## MySQL 9.7: stay on 8.4 LTS

> **Superseded 2026-10-04:** the owner moved every MySQL to 9.7 LTS in a staged upgrade (#1647); see "Upgrading MySQL" in [[deployment]].

8.4 has premier support to 2029-04-30 and extended support to 2032-04-30; 9.7 LTS (released 2026-04-21) extends that by two years. Its headline features (hypergraph optimizer, dynamic data masking, group-replication metrics) do not apply to a single-node 90 MB database with 560 MiB of memory in use. Upgrading costs a dump-and-restore window and lockstep changes to prod, dev, the shared services and both CI workflows (`compose.prod.yaml:264`) for no measurable gain. Migration risk when it comes is low: LTS-to-LTS is supported, nothing references `mysql_native_password` (removed in 9.0), the tuning flags are valid in 9.x, and the restore path is exercised nightly.

**Revisit when** 8.4 nears the end of premier support (2028-2029): move dev, CI and the custom image to 9.7 in one change, run the restore exercise against it first, then roll prod from a fresh dump.

## Valkey: stay on Redis 8

Redis 8 is a supported line (`compose.yaml:12`, digest-pinned in `compose.prod.yaml:80`) and the app uses only core structures: queues through Horizon, cache, sessions and locks (`config/database.php:171`, client phpredis). Redis 8 is tri-licensed (RSALv2, SSPLv1, AGPLv3); AGPL obligations attach to offering modified Redis as a network service, not to running it unmodified as an internal backend. A swap would only add risk: the write-probe healthcheck, AOF replay behaviour and the `noeviction` versus `allkeys-lru` split documented in [[deployment]] would all need re-verifying, with no measurable speed or memory gain on the shared host.

**Revisit when** Redis's licence terms change in a way that affects a self-hosted backend, or a Valkey feature the app needs appears.

## Node 25 and `@types/node`: the runtime stays on 24 LTS

> **Superseded (2026-10-08)** by [[2026-10-08-node-26]]: the runtime moved to Node 26.

Every environment runs Node 24 (`.nvmrc:1`, `package.json:6`), which is active LTS with security support to 2028-04-30, and Node is used only in the asset-build stage, so the runtime image has none. The typings are a major ahead: `@types/node` is `^25` (`package.json:30`), so the typecheck accepts Node 25-only APIs that would fail at runtime in build scripts and tests, and a bot would next propose 26. The fix is to hold `@types/node` on the runtime's major and ignore its major bumps.

**Revisit when** the toolchain (Vitest, Vite, jsdom) lists Node 26 as supported and it has settled into LTS (expected 2027); then move `engines`, `.nvmrc`, the Dockerfile digest and `@types/node` together.

## Off-host backup copy: deferred

**Considered:** shipping each verified nightly dump, encrypted, to an off-host store (an object store with a write-only credential, a second machine, or a private release asset).

Every dump lands on the same host as the database volume, in plain gzip, with 14 days of nightly and 7 days of deploy dumps together (`.github/workflows/nightly-backup.yml:50`, [[deployment]]). One disk failure, host loss or ransomware event removes the data and every recovery point at once. The owner's call: "fine for now on the same host". The data is still effectively a pre-launch fixture, and the on-host restore path is being tested (see the weekly restore-test work) before more machinery is added around it.

**Revisit when** real athletes beyond the current handful are on the app, or the owner stops treating the data as replaceable, whichever comes first. Decide then between an object store, a second machine over SSH and a release asset, and whether Redis (sessions, queue state) is included.

## Vitest on happy-dom: not worth it

**Considered:** swapping the `jsdom` environment (`vitest.config.ts:20`) for happy-dom, or splitting the pure-logic files into a node project.

**Numbers.** `vitest run` is 58.7 s locally, of which per-file environment setup is 88.6 cumulative seconds over 247 files (about 360 ms of jsdom window per file). The same run under happy-dom is 37.0 s, but 24 tests in 11 files fail (mostly focus, `inert` and history behaviour, the same things the overlay tests exist to guard), so the saving would be paid for by porting assertions to a less faithful DOM. `--no-isolate` breaks 106 tests and the pool choice is not a lever (threads 57 s, forks 65 s). Frontend tests are not on the PR critical path (backend shards are about 101-132 s against about 71 s), so PR wall-clock does not move; the gain is about 22 s of local dev loop. A node project for the 32 pure-logic files would save only about 5 s.

**Verdict.** Keep jsdom.

**Revisit when** the frontend suite becomes the PR critical path, or happy-dom's focus, `inert` and history behaviour reaches parity for the overlay tests.
