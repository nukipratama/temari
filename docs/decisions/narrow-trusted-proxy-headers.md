---
title: Narrow the trusted proxy headers, not the trusted hosts
description: Proxy trust is scoped to loopback + private ranges and to X-Forwarded-For/Proto/Port only; trustHosts was rejected because a Host allowlist would fail the container healthcheck.
tags: [decision, infra]
status: accepted
reviewed: 2026-07-29
code_refs:
  - bootstrap/app.php
  - compose.prod.yaml
  - .github/workflows/ci.yml
  - app/Http/Controllers/Auth/StravaAuthController.php
---

# Narrow the trusted proxy headers, not the trusted hosts

**Status:** Accepted (documented 2026-07-29). Supersedes [[trust-all-proxies-cloudflare]].

## Context

[[trust-all-proxies-cloudflare]] recorded `trustProxies(at: '*')`. That is no longer the code: the proxy list had already been narrowed to loopback + RFC1918 ranges ([bootstrap/app.php](bootstrap/app.php)), on the reasoning that cloudflared runs on the host and reaches the container over the docker bridge, so the immediate peer is always private.

Narrowing *which peers* are trusted did not settle *which headers* are honored. `trustProxies` was called with no `headers:` argument, so it inherited Laravel's default set, which includes `X_FORWARDED_HOST` and `X_FORWARDED_PREFIX` alongside FOR/PROTO/PORT (`Illuminate\Http\Middleware\TrustProxies::$headers`).

That combination is exploitable in this topology. The private-range list does not identify the *client*, only cloudflared — and the Cloudflare edge relays client-supplied `X-Forwarded-*` headers through to the origin. So any caller could set `X-Forwarded-Host` and have Laravel's `getHost()` return it, steering every absolute URL the app generates (mail links, share URLs, redirects). `X-Forwarded-Prefix` gives the same leverage over `getBaseUrl()`. Severity is low — this app sends little mail and has no cache layer keyed on host — but it is free to close.

## Decision

**Pass an explicit header set: `X_FORWARDED_FOR | X_FORWARDED_PROTO | X_FORWARDED_PORT`.** These are the three the topology actually needs — `PROTO` to generate `https` behind the TLS-terminating tunnel, `FOR` for real client IPs in logs and rate limiting, `PORT` to keep generated URLs off `:7001`. `HOST` and `PREFIX` are dropped.

Nothing legitimate depends on them. With `X-Forwarded-Host` untrusted, `getHost()` falls back to the `Host` header, which cloudflared forwards intact — so `route('auth.strava.callback')` ([app/Http/Controllers/Auth/StravaAuthController.php:106](app/Http/Controllers/Auth/StravaAuthController.php)) still builds the registered `https://` callback and Strava OAuth is unaffected. If the edge does send `X-Forwarded-Host`, it sends the same hostname `Host` already carries; the two only diverge when someone is forging one.

### Why not `trustHosts`

`trustHosts` was the other candidate and was **rejected**. It works by rejecting requests whose `Host` is not on an allowlist, and this stack makes several legitimate requests with an unroutable Host:

- the container healthcheck is `wget -qO- http://127.0.0.1:7001/up` ([compose.prod.yaml:142-147](compose.prod.yaml)), sending `Host: 127.0.0.1:7001`;
- the deploy's healthcheck and smoke tests curl the same address from the runner ([.github/workflows/ci.yml:412](.github/workflows/ci.yml)).

An allowlist holding only the public domain would 403 all of those, mark the container unhealthy and fail the deploy. Adding `127.0.0.1` back to the allowlist would restore the deploy and simultaneously hand the control back to anyone who can set a Host header. Narrowing the header set needs no allowlist, cannot reject a request, and additionally covers `X-Forwarded-Prefix`, which `trustHosts` does not touch.

## Consequences

- **Enables:** correct `https` URL generation behind the tunnel (unchanged), with host and path-prefix resolution no longer steerable by a forwarded header.
- **Costs:** if a future ingress genuinely terminates on a different hostname than it forwards (a second tunnel hostname, a path-prefixed mount), it will not work until the relevant header is added back deliberately.
- **Gotchas:** the loopback-only `127.0.0.1:7001` binding from [[trust-all-proxies-cloudflare]] is still load-bearing and still the reason trusting private ranges is acceptable at all. This note reduces the blast radius of a forged header; it does not remove the need for that binding. The `TRUSTED_PROXIES` env value in compose remains inert — the list is hardcoded.

## See also

- [[trust-all-proxies-cloudflare]] — the superseded original, and the tunnel topology it documents
- [[deployment]] — the loopback binding, the healthcheck, and the deploy order referenced above
