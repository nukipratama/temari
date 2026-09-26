---
title: Strava grant releases survive account deletion
description: An encrypted, versioned refresh-token mirror and append-only grant history keep Strava slot releases retryable after local disconnection or account deletion.
tags: [decision, strava]
status: accepted
reviewed: 2026-09-26
code_refs:
  - app/Models/StravaGrantEvent.php
  - app/Models/StravaGrantToken.php
  - app/Services/Strava/StravaGrantLedger.php
  - app/Services/Strava/StravaGrantReleaseService.php
  - app/Jobs/Strava/RetryOrphanedStravaGrantReleasesJob.php
  - app/Console/Commands/Strava/SlotsCommand.php
  - app/Services/User/UserEraser.php
  - app/Http/Controllers/Auth/StravaAuthController.php
  - database/migrations/2026_09_26_000002_create_strava_grant_ledger_tables.php
---

> **Partly superseded (2026-09-26) by [[strava-refresh-rejection-names-the-refresh-token]].** Only a token-refresh `400` naming the `RefreshToken` now counts as a rejection; any other `400` keeps the token as a failed release, so the "Operational risk" consequence below no longer applies. The ledger, retention and retry decisions stand.

# Strava grant releases survive account deletion

**Status:** Accepted (documented 2026-09-26)

## Context

Revoking a connection in Temari only stops local reads. The athlete's OAuth grant still occupies a Strava app slot until Strava accepts a deauthorize request. Account deletion removes the connection row, so a transient Strava failure previously erased the only refresh token that could retry the release.

## Decision

Keep an append-only `strava_grant_events` history and one encrypted `strava_grant_tokens` row per Strava athlete. Neither table has a foreign key to `users` or `strava_connections`; their plain user id remains available for operations after account deletion. [StravaGrantLedger::recordGrant](app/Services/Strava/StravaGrantLedger.php#L42) records each OAuth event, and [StravaGrantLedger::persistConnectionRefresh](app/Services/Strava/StravaGrantLedger.php#L72) updates the mirrored token only when the response still belongs to that credential version.

Delete the mirrored token after Strava accepts release or rejects the grant. Any token-refresh `400` is a permanent rejection; during release, a token-refresh `401` also confirms the grant is unavailable, while normal sync refresh `401`s remain transient. The deauthorize request treats `401` / `invalid_grant` as unavailable. Other unsuccessful responses append `release_failed` and retain the latest rotated token through [StravaGrantReleaseService::release](app/Services/Strava/StravaGrantReleaseService.php#L20) and [StravaGrantLedger::recordReleaseOutcome](app/Services/Strava/StravaGrantLedger.php#L163). A daily queued [retry job](app/Jobs/Strava/RetryOrphanedStravaGrantReleasesJob.php#L21) handles grants without an active connection, with no age cutoff; demo grants are excluded. Existing active connections are backfilled when the tables are created in [the ledger migration](database/migrations/2026_09_26_000002_create_strava_grant_ledger_tables.php#L14).

[`strava:slots`](app/Console/Commands/Strava/SlotsCommand.php#L26) provides a holder report and confirmed operator release paths. Releasing a live connection marks only the local connection revoked and leaves the account in place. [UserEraser::releaseStravaGrant](app/Services/User/UserEraser.php#L114) and `strava:remove-athlete` still remove the account even if release fails, leaving the grant available to the retry job. Maintenance refusals record and attempt release without creating a user in [StravaAuthController::refuseNewAthlete](app/Http/Controllers/Auth/StravaAuthController.php#L239).

## Consequences

- **Enables:** release obligations survive account deletion, transient failures can retry with rotated credentials, and stale responses cannot overwrite a newer OAuth grant.
- **Costs:** the app retains an encrypted refresh token after account deletion until Strava confirms release or the grant is confirmed invalid.
- **Operational risk:** any token-endpoint `400` retains the existing permanent-rejection policy. If an orphan release receives `invalid_client` because local Strava credentials are wrong, the ledger may discard its only token while Strava still holds the slot; operators must reconcile such a slot with Strava's dashboard.
- **Privacy:** account deletion removes profile and activity data; only the athlete id, plain user id, grant versions, event timestamps and outcomes, release errors, and encrypted refresh token needed to free the Strava slot remain.

## See also

- [[strava-connect]] — OAuth behavior, release commands, and the account-deletion disclosure
- [[strava-data-compliance]] — the dated `oauth/deauthorize` to `oauth/revoke` change
