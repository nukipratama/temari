---
title: Unseen push subscriptions are pruned after 60 days
description: Each installed app reports its push endpoint at most once a day, and a subscription no app has reported for 60 days is deleted, which clears the row a Home-Screen reinstall leaves behind.
tags: [decision, notifications]
status: accepted
reviewed: 2026-10-04
code_refs:
  - app/Http/Controllers/WebPush/PushSubscriptionController.php
  - app/Console/Commands/Notifications/PrunePushSubscriptionsCommand.php
  - database/migrations/2026_10_04_000100_add_last_seen_at_to_push_subscriptions_table.php
  - resources/js/lib/webPush.ts
  - resources/js/app.tsx
  - routes/console.php
---

# Unseen push subscriptions are pruned after 60 days

**Status:** Accepted (2026-10-04). Closes the gap left open by
[[a-resubscribe-replaces-only-its-own-push-subscription]]; that note's other rules stand.

## Context

Deleting the Home-Screen app wipes its storage, so the reinstalled app cannot name the endpoint it
replaced, and Apple keeps answering 201 to the old one. The row then stayed in
`push_subscriptions` for good. One athlete stopped receiving pushes after a reinstall until their
stale rows were deleted by hand. The server cannot tell a dead install from a live second device,
but the device itself can say it is still there.

## Decision

The owner chose a liveness signal from the device, with a 60-day window (#1718):

- **The device reports in.** On app load and on every Inertia visit, a signed-in, non-demo
  athlete's app posts its current endpoint to `/profile/push/seen` at most once per local day
  (`resources/js/lib/webPush.ts:116`, `resources/js/app.tsx:29`). The server stamps
  `last_seen_at` on that athlete's own row (`app/Http/Controllers/WebPush/PushSubscriptionController.php:68`).
  Saving a subscription stamps it too.
- **Self-heal.** If the server no longer has the endpoint, it answers 404 and the app saves the
  subscription again (`resources/js/lib/webPush.ts:140`), so a device opened after a prune gets
  push back without a tap.
- **The daily prune.** `notifications:prune-push-subscriptions` runs at 02:35 and deletes every
  row not seen in `PrunePushSubscriptionsCommand::UNSEEN_DAYS` (60) days
  (`app/Console/Commands/Notifications/PrunePushSubscriptionsCommand.php:28`).
- **No day-one prune.** The migration backfills every existing row to the migration time
  (`database/migrations/2026_10_04_000100_add_last_seen_at_to_push_subscriptions_table.php:18`).

## Consequences

- A dead row keeps receiving sends for up to 60 days; Apple accepts and drops them.
- A device left unopened for 60 days loses push, including reminders meant to bring the athlete
  back, until the app is opened again.
