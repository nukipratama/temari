---
title: Only a refresh 400 that names the refresh token is a rejection
description: A Strava token-refresh 400 counts as a dead grant only when its errors name the RefreshToken; a bad client id or secret is transient, so a credential mistake cannot revoke every athlete or delete every mirrored token.
tags: [decision, strava]
status: accepted
reviewed: 2026-09-26
code_refs:
  - app/Services/Strava/StravaClient.php
  - app/Services/Strava/StravaGrantLedger.php
  - app/Jobs/Strava/RetryOrphanedStravaGrantReleasesJob.php
---

# Only a refresh 400 that names the refresh token is a rejection

**Status:** Accepted (documented 2026-09-26)

## Context

[[strava-grant-ledger]] treated every token-refresh `400` as a permanent rejection. Strava answers both a dead refresh token and a wrong client id or secret with `400 Bad Request`; the two differ only in `errors[].resource` — `RefreshToken` / `refresh_token` versus `Application` / `client_secret` (or `client_id`). With status-only handling, one bad credential rollout would make every sync refresh revoke its connection and delete its mirrored token, and the nightly orphan run would delete the only token for every orphaned grant while Strava still holds those slots.

## Decision

[StravaClient::requestRefreshedTokens](app/Services/Strava/StravaClient.php#L336) raises `StravaTokenRefreshFailedException` for a `400` only when `errors[]` contains `resource: RefreshToken`; during a release a `401` still confirms the grant is gone. Every other `400` — including an OAuth-style `error: invalid_grant` body, which Strava does not send — is `StravaTokenRefreshTransientException`, whose message carries the first error's resource and field. Sync backs off instead of revoking, and a release records `release_failed` through [StravaGrantLedger::recordReleaseOutcome](app/Services/Strava/StravaGrantLedger.php#L175), keeping the token.

## Consequences

- **Enables:** a credential mistake degrades to retries and a visible `Application client_secret` error in `strava:slots`, and fixing the credential lets the next sync and the next [orphan run](app/Jobs/Strava/RetryOrphanedStravaGrantReleasesJob.php#L21) recover without any lost token.
- **Costs:** a dead grant whose `400` body changes shape is retried as transient instead of closing, until someone notices it in `strava:slots`.
- The deauthorize request's own `401` / `invalid_grant` handling is unchanged.

## See also

- [[strava-grant-ledger]] — the ledger this narrows
- [[strava-connect]] — "Releasing the grant"
