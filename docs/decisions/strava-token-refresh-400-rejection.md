---
title: Token refresh 400 responses retain permanent-rejection behavior
description: Keep status-only HTTP 400 handling for Strava token refresh and document its orphan-release consequence.
tags: [decision, strava]
status: accepted
reviewed: 2026-09-26
code_refs:
  - app/Services/Strava/StravaClient.php
  - app/Services/Strava/StravaGrantLedger.php
  - app/Services/Strava/StravaGrantReleaseService.php
---

# Token refresh 400 responses retain permanent-rejection behavior

**Status:** Accepted (documented 2026-09-26)

This supersedes only the token-removal criterion in [[strava-grant-ledger]]. The ledger, retention, and retry design remain in force.

## Decision

Any HTTP 400 from the token-refresh endpoint is a permanent rejection, independent of its response body. During a release attempt, a 401 from token refresh also means the grant is unavailable. After a successful refresh exchange, the deauthorize request treats 401 or `invalid_grant` as unavailable. Other refresh failures keep the grant open for retry. See [StravaClient::requestRefreshedTokens](app/Services/Strava/StravaClient.php#L331) and [StravaClient::deauthorizeGrantToken](app/Services/Strava/StravaClient.php#L180).

## Consequence

Status-only handling avoids depending on a particular error body and preserves the existing policy. It also means an orphan release can discard its only local token if a token-endpoint 400 comes from local configuration, such as an invalid client secret, while Strava still holds the slot. Operators must reconcile any such slot against Strava's dashboard; the removed token cannot be recovered locally.
