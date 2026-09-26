---
title: Fenced notification delivery recovery
description: Stale notification claims re-arm web push, abandon ambiguous Telegram sends, and fence late workers by claim version.
tags: [decision, notifications]
status: accepted
reviewed: 2026-09-26
code_refs:
  - database/migrations/2026_09_26_000006_add_claim_fencing_to_notification_deliveries.php
  - app/Enums/NotificationDeliveryStatus.php
  - app/Models/NotificationDelivery.php
  - app/Services/Notifications/NotificationDeliveryClaim.php
  - app/Jobs/Notifications/RetryStaleWebPushNotificationJob.php
  - app/Notifications/Channels/TelegramChannel.php
  - app/Notifications/Channels/IdempotentWebPushChannel.php
  - app/Console/Commands/Notifications/RecoverStaleNotificationDeliveriesCommand.php
  - routes/console.php
  - app/Livewire/Pulse/NotificationDeliveryHealth.php
---

# Fenced notification delivery recovery

**Status:** Accepted (2026-09-26)

## Context

A worker can stop after claiming a notification but before it records the provider result. The database then cannot tell whether the provider accepted the send. Releasing every stale claim risks a visible Telegram duplicate; retaining every claim can lose a web push.

## Decision

Each claim stamps `claimed_at` and increments `claim_version`. Workers can settle only the pending row with the version they received. Every five minutes, the recovery command scans pending claims older than 15 minutes. It clears the web-push claim, increments its version, and queues a web-push retry job for the same analysis. The job checks current preferences and channel eligibility before claiming and sending; if the retry is no longer eligible, it settles the unclaimed row as `Failed` with that reason rather than leaving it marked in flight. The command changes stale Telegram claims to terminal `Abandoned`, also incrementing the version, so an automatic retry cannot repeat an ambiguous message.

Claim and settle fencing is implemented in `app/Services/Notifications/NotificationDeliveryClaim.php:31` and `app/Services/Notifications/NotificationDeliveryClaim.php:75`; both channel wrappers pass the version at `app/Notifications/Channels/TelegramChannel.php:90` and `app/Notifications/Channels/IdempotentWebPushChannel.php:72`. The scheduled sweep is registered at `routes/console.php:159`, and the operator signal is rendered at `app/Livewire/Pulse/NotificationDeliveryHealth.php:28`.

| Crash window | Recovery | Delivery tradeoff |
|---|---|---|
| Before the claim | No claim exists; the queued attempt can claim and send. | No delivery was started by this worker. |
| After claim, before provider call | Web push is re-armed; Telegram becomes `Abandoned`. | Telegram can be marked abandoned even though nothing arrived. |
| Provider accepted, before `sent` is recorded | Web push is re-armed; Telegram becomes `Abandoned`. | Web push may duplicate on retry; Telegram avoids an automatic visible duplicate. |
| Provider failure recorded | The row is `Failed`; a later attempt can claim a new version and retry. | The provider error remains visible until the retry settles. |
| Old worker finishes after recovery or reclaim | Its version no longer matches, so its completion is a no-op. | A late result cannot overwrite the newer state. |

The scheduled command queues a web-push retry; the queue worker performs the provider send. The `Notification Delivery` Pulse card surfaces `Abandoned` rows with their channel, analysis ID, and stored reason. For Telegram, an operator checks the chat outcome before using the existing manual **Send notification** control; that explicit resend can duplicate a message if Telegram accepted the original attempt.

On schema rollback, the down migration maps `Abandoned` to legacy `Sent` and retains an unknown-outcome reason in `error`. That keeps the previous enum readable and prevents its failed-row retry path from resending Telegram automatically.

## Consequences

- **Enables:** automatic recovery of stranded web-push claims without letting stale workers overwrite a newer attempt.
- **Costs:** web push accepts possible duplicates after an ambiguous provider result; Telegram requires a human decision after an ambiguous result.
- **Privacy:** delivery rows keep the analysis ID, channel, claim timestamps, and provider error already used for delivery triage.

## See also

- [[telegram-notifications]] — outbound channels and manual resend controls
- [[scheduler]] — the scheduled recovery cadence and locks
