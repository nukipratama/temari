---
title: Trust all proxies behind the Cloudflare tunnel
description: Laravel trusts every proxy so it honors X-Forwarded-Proto and generates https URLs, safe because the app port is loopback-only behind the tunnel.
tags: [decision, infra]
status: accepted
reviewed: 2026-06-20
code_refs:
  - bootstrap/app.php
  - compose.prod.yaml
  - config/services.php
---

# Trust all proxies behind the Cloudflare tunnel

**Status:** Accepted (documented 2026-06-20)

## Context

In prod the app sits behind a Cloudflare Tunnel: TLS terminates at the Cloudflare edge and `cloudflared` forwards plain HTTP to the origin with `X-Forwarded-Proto: https`. Laravel sees an HTTP request, so without honoring that header it generates `http://` URLs — and the Strava OAuth `redirect_uri` it builds then mismatches the registered `https://` callback, breaking login. The normal fix (trust a specific proxy IP) doesn't work here: Cloudflare doesn't announce a stable origin IP we could allowlist.

## Decision

We decided to **trust all proxies**, set in [bootstrap/app.php](bootstrap/app.php) via `$middleware->trustProxies(at: '*')`. Laravel then honors `X-Forwarded-Proto` from any upstream and generates correct `https` URLs, so the Strava OAuth redirect built from [config/services.php](config/services.php) (`services.strava`) matches the registered callback.

This is safe because the app is **not publicly reachable except through the tunnel**: in [compose.prod.yaml](compose.prod.yaml) the app container publishes its port as `127.0.0.1:7001:7001` — loopback-only — so nothing on the LAN or internet can reach it directly to spoof forwarded headers. The only path in is `cloudflared` on the host hitting `127.0.0.1:7001`.

(Note: a `TRUSTED_PROXIES: "127.0.0.1"` env value exists in the compose file but is currently unread — `bootstrap/app.php` hardcodes `at: '*'`. It's kept for documentation/future use.)

## Consequences

- **Enables:** correct `https` URL/redirect generation behind a TLS-terminating tunnel with no stable origin IP, so Strava OAuth and any absolute-URL generation work.
- **Costs:** trusting `*` means if the app ever became directly reachable, a client could spoof `X-Forwarded-*` headers (fake the scheme or client IP). The loopback-only port binding is what makes that acceptable — that binding is now a load-bearing security control, not just a convenience.
- **Gotchas:** the safety rests entirely on the `127.0.0.1:7001` binding and the tunnel being the sole ingress. Exposing the port (e.g. binding `0.0.0.0` for debugging) silently removes the protection while `trustProxies(at: '*')` stays. The `TRUSTED_PROXIES` env var is inert; changing it has no effect today.

## See also

- [[deployment]] — the Cloudflare tunnel + loopback binding topology
