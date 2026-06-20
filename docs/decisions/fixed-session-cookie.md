---
title: Fixed session cookie name + Redis prefixes (not APP_NAME-derived)
description: The session cookie name and Redis/cache key prefixes are pinned to fixed literals so an APP_NAME edit can't log everyone out.
tags: [decision, infra]
status: accepted
reviewed: 2026-06-20
code_refs:
  - compose.prod.yaml
  - config/session.php
  - config/database.php
---

# Fixed session cookie name + Redis prefixes (not APP_NAME-derived)

**Status:** Accepted (documented 2026-06-20)

## Context

Laravel derives the session cookie name from `APP_NAME` by default: [config/session.php](config/session.php) sets `cookie` to `Str::slug(APP_NAME).'-session'`, and [config/database.php](config/database.php) derives the Redis key prefix from `Str::slug(APP_NAME).'-database-'`. `APP_NAME` here is a human-facing tagline (`"TemanLari - Setiap Langkah Berarti"`), exactly the kind of cosmetic string someone edits. If the cookie name is derived from it, any tagline tweak renames the cookie on the next deploy and every existing session stops matching — everyone is logged out. PR #21's rename did exactly this.

## Decision

We decided to **pin the cookie name and the Redis/cache prefixes to fixed literals**, independent of `APP_NAME`, in [compose.prod.yaml](compose.prod.yaml)'s shared app env:

- `SESSION_COOKIE: temanlari-setiap-langkah-berarti-session`
- `REDIS_PREFIX: temanlari-setiap-langkah-berarti-database-`
- `CACHE_PREFIX: temanlari-setiap-langkah-berarti-cache-`

These values equal the *current* `APP_NAME`-derived slugs, so adopting them was a no-op for existing sessions and cache keys. The config defaults stay `APP_NAME`-derived for local/dev where no override is set; prod simply overrides them. Sessions live on Redis DB 0, cache on DB 1, so the two prefixes also keep those keyspaces namespaced apart.

## Consequences

- **Enables:** `APP_NAME` / tagline becomes a free-to-edit cosmetic string — changing it no longer rotates the cookie or shifts Redis key prefixes, so no deploy logs users out over a copy change.
- **Costs:** the literal is frozen to a name that no longer tracks `APP_NAME`. A future deliberate rebrand that *wants* fresh cookies/keys must change these literals on purpose (and accept the logout), and the coupling is now an env convention to remember rather than automatic.
- **Gotchas:** these are set under `environment:` in compose, not the host `.env`; the host `.env` (`env_file:`) can't accidentally re-derive them. Local dev without the override still falls back to the `APP_NAME` slug, so a dev `APP_NAME` divergence yields a different cookie locally — harmless, but don't assume the literal applies everywhere.

## See also

- [[deployment]] — the prod compose stack and Redis DB layout
