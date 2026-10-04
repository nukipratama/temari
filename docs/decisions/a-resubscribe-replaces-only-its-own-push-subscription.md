---
title: A re-subscribe replaces only its own push subscription
description: Dead web-push subscriptions are pruned only on exact evidence (a push-service 404/410, or the same install naming the endpoint it replaces), never by host, age or count, because a live second Apple device is indistinguishable from a dead install.
tags: [decision, notifications]
status: accepted
reviewed: 2026-10-04
code_refs:
  - app/Http/Controllers/WebPush/PushSubscriptionController.php
  - app/Http/Requests/StorePushSubscriptionRequest.php
  - app/Listeners/LogRejectedWebPush.php
  - resources/js/lib/webPush.ts
  - public/sw.js
---

# A re-subscribe replaces only its own push subscription

> **Partly superseded (2026-10-04) by [[unseen-push-subscriptions-are-pruned-after-60-days]].** A subscription no installed app has reported for 60 days is now pruned, which removes the row a Home-Screen reinstall leaves behind. The rest of this decision stands.

**Status:** Accepted (2026-10-04)

## Context

One athlete had eight Apple push subscriptions, one per reinstall or "fix" tap, and every
notification fanned out to all of them. None were pruned because the only deletion was the
webpush package's own: `NotificationChannels\WebPush\ReportHandler` deletes a subscription when
the push service answers 404 or 410. Apple kept answering 201 for all eight after the web app
was deleted, so that signal never arrived.

What the server can know about a subscription is its endpoint and whether the push service
accepted a send. The endpoint is opaque and unique per browser install, and an iPhone, an iPad
and a Mac all subscribe on `web.push.apple.com`. A 201 does not mean the device received
anything. So no server-side rule (newest per host, a per-host cap, a maximum age without a
rejected send) can tell a dead reinstall apart from a live second device: each one deletes a
live iPad or Mac in a plausible case. A cap keeps the newest rows, and reinstalls are newer
than a long-lived iPad.

## Decision

Prune only on exact evidence:

- **The push service says it is gone.** A 404/410 deletes the row, as the package already does.
- **The install says it replaced it.** The client remembers the endpoint it last saved
  (`resources/js/lib/webPush.ts:106`) and sends it as `previous_endpoint` when it subscribes
  again (`resources/js/lib/webPush.ts:80`). The server deletes that endpoint from the signed-in
  user's own subscriptions (`app/Http/Controllers/WebPush/PushSubscriptionController.php:33`),
  never another user's, and ignores it when it equals the endpoint being saved
  (`app/Http/Requests/StorePushSubscriptionRequest.php:59`). This covers the "fix" tap: iOS
  revoked the old subscription but kept the install's storage.

Every rejected send now logs one `webpush.rejected` warning with the status, a truncated reason,
the push-service host, the subscription id and the user id
(`app/Listeners/LogRejectedWebPush.php:23`). The endpoint path and keys are never logged; URLs
in a transport error's reason are cut to their host.

The service worker shows a fallback notification for a push with no readable title or body
(`public/sw.js:88`), because iOS revokes the subscription of a web app whose pushes are not
displayed.

## Consequences

- A reinstall from the Home Screen still leaves the old subscription behind: deleting the web
  app wipes its storage, so the new install cannot name what it replaced. Those rows go when
  Apple starts answering 410. Closing that gap needs a liveness signal from the device itself,
  such as the service worker acknowledging each push, and an owner call on how long a silent
  device may stay away before it stops being pushed.
- A live second device is never pruned by these rules.
