---
title: Narration follows the athlete, not the run
description: Scheduled narration is spent on athletes who opened the app in the last 7 days, read from users.last_seen_at, while plan rows, metrics and compliance keep running for everyone.
tags: [decision, ai, cost]
status: accepted
reviewed: 2026-09-15
code_refs:
  - app/Actions/AI/RecentlyActiveUsers.php
  - app/Http/Middleware/StampLastSeen.php
  - app/Console/Commands/AI/TrendReadCommand.php
  - app/Console/Commands/Run/RegeneratePlanCommand.php
  - database/migrations/2026_09_15_090000_add_last_seen_at_to_users_table.php
---

# Narration follows the athlete, not the run

**Status:** Accepted (documented 2026-09-15)

## Context

Every scheduled narration cadence decided who to bill for from the athlete's *runs*: a run inside
a rolling 7-day window made them "active". `RecentlyActiveUsers` derived that from
`activity_details.start_date_local`, and `ai:trend-read` carried its own copy of the same query.
`plan:regenerate` had no gate at all — it narrated up to nine rows per athlete per week for
everyone with an account.

A run reaches the app on its own. Strava's webhook syncs it, the cascade narrates it, and nobody
has to be looking for any of that to happen. So run recency answers "is this athlete still
running", which is not the question the spend depends on: whether anyone will read what was
written. An athlete who logs runs from a watch for a month without opening the app was billed for a
month of briefings, profile voices, trend reads and plan narration that nothing rendered.

## Decision

`users.last_seen_at` — a nullable timestamp stamped by `StampLastSeen` on authenticated web
requests, at most once per calendar day per athlete (the comparison is on the date, so a session of
fifty requests costs one `UPDATE`). The demo identity is never stamped; it is shared, public, and
excluded from every billing cadence anyway.

`RecentlyActiveUsers` reads that column: active means `last_seen_at` inside the last
`ACTIVE_WINDOW_DAYS` (7), demo excluded. Every scheduled narration cadence draws its list from it —
`ai:daily-briefing` and `ai:weekly-profile` already did, `ai:trend-read` drops its duplicated
query, and `plan:regenerate` gains the gate it never had.

The gate covers the narration *request* only. The periodizer still regenerates every athlete's plan
rows, `plan:score-compliance` still scores them, `trend:snapshot-daily` still grows their history,
and metrics are still computed on ingest — all deterministic, all free, all for everyone. A dormant
athlete who opens the app finds their plan and numbers current and their narration one cadence away,
not a month of catching up.

## Consequences

- An athlete who has never opened the app since this shipped has a null `last_seen_at` and is
  narrated by no scheduled cadence until their next visit. That is the decision working, not a
  migration gap: the first page they load stamps the column, and `ai:catch-up` stages the kickoff
  rows their dormant days never created.
- Run recency no longer appears in any scheduled gate. The tests that proved a backfill's
  `analyzed_at` stamp must not read as activity are now about `last_seen_at` instead; the hazard
  they guarded (a just-connected athlete billed daily for a decade-old history) cannot arise from a
  column only a page view writes.
- The demo exclusion moves into `RecentlyActiveUsers` for `ai:trend-read` and `plan:regenerate`,
  which is where `DemoBillingExclusionTest` now reads it from for those two commands.

## Alternatives considered

**Keep the run-date signal and add `last_seen_at` as a second condition.** Rejected: two signals
that disagree need a rule for which wins, and the run-date half never answered the question the
spend turns on.

**Backfill `last_seen_at` from each athlete's last run so nobody goes quiet on deploy.** Rejected:
it writes a fact nobody observed, and it re-creates exactly the signal this replaces for one window.

See [[demo-user-billing-exclusion]] · [[history-narrates-on-demand]] · [[twelve-week-narration-cutoff]]
