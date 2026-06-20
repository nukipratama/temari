---
title: Defer config:cache to deploy time; never at build or in CI tests
description: config:cache only runs inside the live container at deploy, where the real runtime env is loaded
tags: [decision, infra]
status: accepted
reviewed: 2026-06-20
code_refs:
  - Dockerfile
  - .github/workflows/ci.yml
  - README.md
---

# Defer config:cache to deploy time; never at build or in CI tests

**Status:** Accepted (documented 2026-06-20)

## Context

`php artisan config:cache` freezes whatever `env()` resolves *at the moment it runs* into a static cached config; runtime `env()` reads are then ignored. That makes **when** it runs load-bearing, and two tempting moments are both wrong:

- **At Docker build time** there is no `.env` loaded, so `env()` falls back to PHP defaults — e.g. `DB_CONNECTION` defaults to `sqlite` in `config/database.php` — and bakes those defaults in. The real env from `compose.prod.yaml` would then be ignored.
- **In the CI test step**, caching would freeze `.env.example` values, because `phpunit.xml` `<env>` overrides only apply *after* PHPUnit boots — silently breaking dispatch-assertion tests that depend on those overrides.

## Decision

We decided that config caching happens **only at deploy time, inside the already-running container**, where the real runtime env is loaded.

- The runtime stage in the [Dockerfile](../../Dockerfile) deliberately does **not** bake `config:cache`; it runs only `package:discover`, with a comment spelling out the `sqlite`-default trap. Build time is for autoload/package discovery, not config.
- The deploy job in [ci.yml](../../.github/workflows/ci.yml) caches via an `php artisan optimize` step run with `compose exec` against the live container, after the services roll. The CI **test** steps invoke `pest` directly and never `config:cache`, so `phpunit.xml` overrides apply normally.
- This is documented in the deploy walkthrough in the [README](../../README.md).

## Consequences

- Production reads its true env (DB, cache, queue) instead of frozen `.env`-less defaults.
- Dispatch-assertion tests see `phpunit.xml` overrides, not `.env.example`.
- The tradeoff is a small per-deploy cost: `optimize` runs against the running container rather than being prebaked into the image.

## See also

- [[deployment]]
