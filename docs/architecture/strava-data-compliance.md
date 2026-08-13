---
title: Strava data compliance
description: What Strava's API terms oblige Temari to do and where each obligation is enforced — the endpoints we actually call, cross-user isolation, the 2027 host move, and the inference-not-training position on AI.
tags: [architecture, strava]
status: living
reviewed: 2026-08-13
code_refs:
  - app/Services/Strava/StravaClient.php
  - app/Services/Strava/ActivityFetcher.php
  - app/Services/Strava/ZoneFetcher.php
  - app/Support/DataUseStatement.php
  - tests/Feature/Compliance/CrossUserDataIsolationTest.php
---

# Strava data compliance

The operational mechanics of talking to Strava live in [[strava-client]]; this note is the **obligations** side, and where each one is enforced so a reviewer can check rather than trust.

## The endpoints we actually call

Every read goes through [`StravaClient::get()`](app/Services/Strava/StravaClient.php#L48), so the call sites are enumerable:

| Endpoint | Call site |
| --- | --- |
| `/athlete/activities` | [ActivityFetcher](app/Services/Strava/ActivityFetcher.php#L63) |
| `/activities/{id}` | [CleanupDeletedActivityJob](app/Jobs/Strava/CleanupDeletedActivityJob.php#L162) |
| `/activities/{id}/streams` | [ActivityPipeline](app/Services/Run/Ingest/ActivityPipeline.php#L256) |
| `/athlete/zones` | [ZoneFetcher](app/Services/Strava/ZoneFetcher.php#L44) |
| `/athlete` | [VerifyStravaRevocationJob](app/Jobs/Strava/VerifyStravaRevocationJob.php#L52) |
| `/push_subscriptions` | [WebhookSubscribeCommand](app/Console/Commands/Strava/WebhookSubscribeCommand.php#L25) |

**No club endpoints and no segment endpoints, including Explore Segments.** Strava removes the club endpoints and restricts Explore Segments on **2026-09-01**; both are a no-op for Temari, and the table above is what keeps that checkable. The requested OAuth scopes are correspondingly narrow ([StravaAuthController](app/Http/Controllers/Auth/StravaAuthController.php#L28)): `read`, `activity:read_all`, `profile:read_all` and nothing else.

## One athlete's data is never shown to another

Strava has barred displaying one user's activity data to any other user since **Nov 2024**. That is enforced per-controller (ownership guards and user-scoped queries), and the guard against it silently regressing is [CrossUserDataIsolationTest](tests/Feature/Compliance/CrossUserDataIsolationTest.php), which sweeps the **registered route table** rather than a fixed list:

- every authenticated route carrying a resource parameter must answer 403/404 when handed a row another user owns;
- a route parameter the sweep cannot build **fails the test** until it is registered, so a newly added endpoint opts in instead of slipping past;
- every parameterless authenticated GET page is loaded as a stranger and must not contain the other user's run.

A third case renders the same run for its own owner, so a clean sweep can never be a vacuous one. `routes/**` is pinned to this directory in the Pest TIA watch map ([tests/Pest.php](tests/Pest.php)), because reading the route table produces no coverage edge that would otherwise re-run the sweeps when a route is added.

## AI: inference, not training

Strava's terms bar using its data to train AI models. Temari sends run stats to Azure OpenAI and renders the text that comes back — inference only, no training or fine-tuning on either side. Because that claim has to be said the same way everywhere it appears, the wording is a single shared constant, [DataUseStatement](app/Support/DataUseStatement.php), rendered today on [Settings](resources/js/pages/Settings/Index.tsx) and available to the terms and privacy pages when they land. See [[ai-pipeline]] for what is actually sent.

## Dated obligations

- **2026-06-30** — standard API access requires a paid Strava subscription. Account-level, no code hook.
- **2026-09-01** — club endpoints removed, Explore Segments restricted. No-op here, see above.
- **2027-01-04** — `api-v3.strava.com` starts serving. It is **not the deadline it looks like**: it is the date the replacement host becomes *available*, and Strava has announced no shutdown date for `www.strava.com/api/v3`. The host is already a config value, `STRAVA_API_BASE_URL`, defaulting to the host that answers today; see [[strava-client]] before flipping it.
- **2027-06-01** — access tokens must be sent in request headers rather than form params, and `oauth/deauthorize` is retired in favour of `oauth/revoke`. Both are no-ops here: API reads authenticate with a bearer header via `withToken()` ([StravaClient](app/Services/Strava/StravaClient.php#L63)), and Temari never calls `oauth/deauthorize` at all — a revocation is something the athlete does on Strava's side, which we detect from a 401 ([VerifyStravaRevocationJob](app/Jobs/Strava/VerifyStravaRevocationJob.php#L52)).

The dates above are from Strava's [V3 API changelog](https://developers.strava.com/docs/changelog/), which is the thing to re-read before acting on any of them.

## See also

[[strava-client]] · [[strava-circuit-breaker-rate-limit]] · [[strava-connect]] · [[settings]] · [[ai-pipeline]]
