---
title: Telegram notifications
description: Linking a Telegram account, the per-type notification toggles, and how post-run / weekly-recap / monthly-recap narration is pushed to the bot.
tags: [feature, telegram]
status: living
reviewed: 2026-07-19
code_refs:
  - app/Services/Telegram/TelegramClient.php
  - app/Services/Telegram/TelegramLinkToken.php
  - app/Services/Telegram/NotifiableAnalysis.php
  - app/Jobs/Telegram/HandleTelegramUpdateJob.php
  - app/Notifications/AnalysisReadyNotification.php
  - app/Notifications/Channels/TelegramChannel.php
  - app/Notifications/StreakReminderNotification.php
  - app/Notifications/TestNotification.php
  - app/Http/Controllers/Notifications/Concerns/PushesAnalysisNotification.php
  - app/Http/Controllers/Notifications/SendActivityNotificationController.php
  - app/Http/Controllers/Notifications/SendWeeklyRecapNotificationController.php
  - app/Http/Controllers/Notifications/SendMonthlyRecapNotificationController.php
  - app/Console/Commands/AI/DailyBriefingCommand.php
  - app/Console/Commands/Gamification/StreakRemindCommand.php
  - app/Http/Controllers/Telegram/TelegramWebhookController.php
  - app/Http/Controllers/Telegram/TelegramConnectionController.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - app/Console/Commands/Telegram/SetWebhookCommand.php
  - app/Console/Commands/Telegram/ListenCommand.php
  - app/Http/Controllers/SettingsController.php
  - resources/js/pages/Pengaturan/Index.tsx
  - resources/js/components/SendNotificationButton.tsx
  - resources/js/components/EnableNotificationsModal.tsx
  - resources/js/hooks/useNotificationsReachable.ts
  - routes/web.php
---

# Telegram notifications

The first outbound channel: Temari pushes the most "alive" narration to the user's Telegram so the companion feels present without them opening the app. Three events notify, all keyed off the same chokepoint that finalizes any narration ([[ai-pipeline]]): the **post-run speech** (minutes after a Strava activity syncs), the **weekly recap** (Monday morning), and the **monthly recap** (start of the next month). Each is an independent opt-in toggle; adding another event is one entry in [NotifiableAnalysis](../../app/Services/Telegram/NotifiableAnalysis.php). A fourth push, the **streak reminder** (Saturday 18:00, see [[streak-reminders]]), goes out over the same channels but isn't narration-keyed and has no toggle of its own: it piggybacks `weekly_recap`.

Each of the post-run / weekly / monthly types also has a manual **"Kirim notifikasi"** push: a per-page button ([SendNotificationButton](../../resources/js/components/SendNotificationButton.tsx)) on the run detail, weekly recap (Jejak), and monthly recap (Kalender) pages. It is deliberately **channel-neutral** — the force-push fans out to every channel the user has wired, so the button never names one. It is gated on the narration being Done and on the user being reachable at all: `telegramConnected || webPushSubscribed`, both shared Inertia props ([HandleInertiaRequests](../../app/Http/Middleware/HandleInertiaRequests.php)) combined by the [useNotificationsReachable](../../resources/js/hooks/useNotificationsReachable.ts) hook. A user with neither wired still sees the pill, muted; tapping it opens [EnableNotificationsModal](../../resources/js/components/EnableNotificationsModal.tsx), which points at Pengaturan rather than pushing one channel. Each controller (`SendActivityNotificationController`, `SendWeeklyRecapNotificationController`, `SendMonthlyRecapNotificationController`) shares its force-dispatch body via the [PushesAnalysisNotification](../../app/Http/Controllers/Notifications/Concerns/PushesAnalysisNotification.php) trait: `force: true`, so it bypasses the per-type opt-in toggle and the once-only delivery guard and can be re-sent. The daily briefing has no manual push button; it only fires automatically, once per day per user (the `Analysis` row is upserted per date-keyed discriminator, so `markDone()` fires at most once for that day's row).

## Setup (one-time, out of band)

A bot is created in Telegram's @BotFather (`/newbot`), which also sets its name, @username, and avatar — the bot's distinct identity. The token lands in `.env` (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`), wired through [config/services.php](../../config/services.php). A token's webhook and `getUpdates` polling are mutually exclusive, so use **two bots**: a prod bot (webhook) and a separate test bot (polled locally). [TelegramClient](../../app/Services/Telegram/TelegramClient.php) wraps the Bot API over the `Http` facade.

- **Prod** registers the push webhook once via `telegram:set-webhook` ([SetWebhookCommand](../../app/Console/Commands/Telegram/SetWebhookCommand.php)). The `TELEGRAM_WEBHOOK_SECRET` may only contain `A-Z a-z 0-9 _ -` (1-256 chars) per Telegram's API; a base64 value (the `+ / =` from `key:generate`) is rejected with an opaque 400, so the command validates the charset up front and suggests `openssl rand -hex 32`.
- **Local dev** runs `telegram:listen` ([ListenCommand](../../app/Console/Commands/Telegram/ListenCommand.php)), a manual foreground long-poll (like `queue:listen`, never scheduled) that needs no public URL and feeds the same linking job the webhook does.

## Linking an account

Telegram has no OAuth, so the logged-in web session carries its identity through the bot. The Pengaturan page ([SettingsController](../../app/Http/Controllers/SettingsController.php) `resolveTelegram()`) mints a signed deep-link token ([TelegramLinkToken](../../app/Services/Telegram/TelegramLinkToken.php), 60-min TTL) and renders a Telegram-branded "Hubungkan Telegram" button pointing at `t.me/<bot>?start=<token>`.

When the user taps Start, the update reaches [HandleTelegramUpdateJob](../../app/Jobs/Telegram/HandleTelegramUpdateJob.php) — via the webhook ([TelegramWebhookController](../../app/Http/Controllers/Telegram/TelegramWebhookController.php), CSRF-exempt and gated on the `X-Telegram-Bot-Api-Secret-Token` header) in prod, or `telegram:listen` in dev. It verifies the token (signature + expiry), then either links (storing the server-reported `chat_id`, replying with an account-naming welcome, and **consuming the token** so a leaked link can't be replayed), replies that the link is no longer valid without linking, or replies generically to garbage. `/stop` revokes. All reply copy is Temari-voiced in [TelegramReplies](../../app/Services/Telegram/TelegramReplies.php). The decision behind this flow is [[telegram-account-linking]].

## Preferences + disconnect

The Pengaturan page ([Pengaturan/Index.tsx](../../resources/js/pages/Pengaturan/Index.tsx)) shows three switches (`post_run`, `weekly_recap`, `monthly_recap`, the channel-neutral [NotificationPreference](../../app/Models/NotificationPreference.php) columns) and a "Putuskan" button once connected; [TelegramConnectionController](../../app/Http/Controllers/Telegram/TelegramConnectionController.php) persists the toggles and revokes on disconnect. The Telegram connect button keeps Telegram's brand mark and blue (not recolored), the way the Strava button is left as-shipped (see [[strava-connect]]). There is no dedicated streak-reminder toggle: the [[streak-reminders]] Saturday nudge piggybacks `weekly_recap` ([StreakReminderNotification](../../app/Notifications/StreakReminderNotification.php), [StreakRemindCommand](../../app/Console/Commands/Gamification/StreakRemindCommand.php)), so opting out of weekly recaps also silences streak reminders.

## Sending

`AnalysisService::markDone()` fans out [AnalysisReadyNotification](../../app/Notifications/AnalysisReadyNotification.php) — a queued Laravel notification — for the notifiable types. Its `via()` resolves the user behind the analysis ([NotifiableAnalysis](../../app/Services/Telegram/NotifiableAnalysis.php)) and gates the send: not the demo user, a configured bot token, a non-revoked connection, the recency window, and the per-type opt-in — an unwired or opted-out user resolves to no channels and nothing is enqueued. Delivery runs in [TelegramChannel](../../app/Notifications/Channels/TelegramChannel.php), which holds the `notification_deliveries` unique `(analysis_id, channel)` claim so a queued retry never double-sends. The completion-hook side and its guards live in [[ai-pipeline]]. A manual push (see above) sets `force: true`, bypassing the recency + opt-in gates and the once-only claim CHECK (it still records the claim, and still requires a non-revoked connection). A send that hits a permanent 4xx (a 403 blocked bot, a gone chat; anything but a 429) is treated like a `/stop`: the channel uses [RevokesConnectionOnPermanentFailure](../../app/Jobs/Telegram/Concerns/RevokesConnectionOnPermanentFailure.php) to `markRevoked()` and stop instead of retrying a non-retryable error forever. Delivery going through a Laravel notification channel is what lets a second channel (web push) join later as a single `via()` entry.

The message shares one **title → body** shape across Telegram and web push, both built from [NotifiableAnalysis](../../app/Services/Telegram/NotifiableAnalysis.php) (`title()` + `format()`). The **title** is a dynamic, data-aware line — an emoji plus a short first-person phrase: `🏃 Lari 8,2K kamu udah masuk! 🏁` (the run distance, dropped when unknown), `📊 Rekap minggu lalu udah siap`, and `🗓️ Rekap Juli udah siap` (the month named from the recap's discriminator, falling back to `Rekap bulanan…` when blank). Below the title sits a blank line, the LLM narration verbatim, a one-line metrics summary (distance · duration · pace · HR, each dropped when null) for post-run, and a tap-through deep link to the activity, run-history, or calendar page. Web push carries the same title with the narration as its body, and is sent at `Urgency: high` so the OS doesn't defer it under Low Power Mode.

## See also

[[ai-pipeline]] · [[telegram-account-linking]] · [[strava-connect]] · [[profile]]
