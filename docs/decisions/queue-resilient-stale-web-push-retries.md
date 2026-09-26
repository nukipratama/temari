---
title: Queue-resilient stale web-push retries
description: Keep a stale web-push claim discoverable until its retry worker atomically takes the next claim version.
tags: [decision, notifications]
status: accepted
reviewed: 2026-09-26
code_refs:
  - app/Services/Notifications/NotificationDeliveryClaim.php
  - app/Console/Commands/Notifications/RecoverStaleNotificationDeliveriesCommand.php
  - app/Jobs/Notifications/RetryStaleWebPushNotificationJob.php
  - app/Notifications/Channels/IdempotentWebPushChannel.php
  - routes/console.php
---

# Queue-resilient stale web-push retries

**Status:** Accepted (2026-09-26)

**Supersedes:** [[fenced-notification-delivery-recovery]] for the web-push retry handoff.

The recovery command queues a retry for each stale web-push claim but leaves the claim row unchanged (`app/Services/Notifications/NotificationDeliveryClaim.php:158`, `app/Console/Commands/Notifications/RecoverStaleNotificationDeliveriesCommand.php:17`). The retry reaches the regular channel, which can atomically take over a pending claim whose timestamp is older than the stale cutoff and stamp a new version before sending (`app/Services/Notifications/NotificationDeliveryClaim.php:31`, `app/Notifications/Channels/IdempotentWebPushChannel.php:51`). If queue dispatch fails or the command stops before dispatch, a later sweep still finds the stale claim. Concurrent retry jobs may be queued, but only one can claim the current version.

The retry rechecks current channel eligibility first. If preferences suppress it, the worker settles only the same stale claim version as `Failed` (`app/Jobs/Notifications/RetryStaleWebPushNotificationJob.php:40`, `app/Services/Notifications/NotificationDeliveryClaim.php:105`). Telegram remains terminal `Abandoned` after an ambiguous stale send, avoiding an automatic duplicate (`app/Services/Notifications/NotificationDeliveryClaim.php:177`).
