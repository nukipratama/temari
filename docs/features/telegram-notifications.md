---
title: Telegram notifications
description: Linking a Telegram account, the per-type notification toggles, and how post-run / weekly-recap / monthly-recap narration is pushed to the bot.
tags: [feature, telegram]
status: living
reviewed: 2026-07-04
code_refs:
  - app/Services/Telegram/TelegramClient.php
  - app/Services/Telegram/TelegramLinkToken.php
  - app/Services/Telegram/NotifiableAnalysis.php
  - app/Jobs/Telegram/HandleTelegramUpdateJob.php
  - app/Jobs/Telegram/SendTelegramNotificationJob.php
  - app/Http/Controllers/Telegram/Concerns/PushesAnalysisToTelegram.php
  - app/Http/Controllers/Telegram/SendActivityNotificationController.php
  - app/Http/Controllers/Telegram/SendWeeklyRecapNotificationController.php
  - app/Http/Controllers/Telegram/SendMonthlyRecapNotificationController.php
  - app/Console/Commands/AI/DailyBriefingCommand.php
  - app/Http/Controllers/Telegram/TelegramWebhookController.php
  - app/Http/Controllers/Telegram/TelegramConnectionController.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - app/Console/Commands/Telegram/SetWebhookCommand.php
  - app/Console/Commands/Telegram/ListenCommand.php
  - app/Http/Controllers/ProfileController.php
  - resources/js/pages/Aku.tsx
  - resources/js/components/SendToTelegramButton.tsx
  - routes/web.php
---

# Telegram notifications

The first outbound channel: Temari pushes the most "alive" narration to the user's Telegram so the companion feels present without them opening the app. Four events notify, all keyed off the same chokepoint that finalizes any narration ([[ai-pipeline]]): the **post-run speech** (minutes after a Strava activity syncs), the **weekly recap** (Monday morning), the **monthly recap** (start of the next month), and the **daily briefing** headline (the `ai:daily-briefing` kickoff). Each is an independent opt-in toggle; adding another event is one entry in [NotifiableAnalysis](../../app/Services/Telegram/NotifiableAnalysis.php). Unlike the other three, `notify_daily_briefing` defaults to **false**: it's the highest-frequency push, so existing users aren't auto-enrolled into a new daily message.

Each of the post-run / weekly / monthly types also has a manual "Kirim ke Telegram" push: a per-page button ([SendToTelegramButton](../../resources/js/components/SendToTelegramButton.tsx)) on the run detail, weekly recap (Jejak), and monthly recap (Kalender) pages, gated on the `telegramConnected` shared Inertia prop ([HandleInertiaRequests](../../app/Http/Middleware/HandleInertiaRequests.php)) and on the narration being Done. Each controller (`SendActivityNotificationController`, `SendWeeklyRecapNotificationController`, `SendMonthlyRecapNotificationController`) shares its force-dispatch body via the [PushesAnalysisToTelegram](../../app/Http/Controllers/Telegram/Concerns/PushesAnalysisToTelegram.php) trait: `force: true`, so it bypasses the per-type opt-in toggle and the once-only delivery guard and can be re-sent. The daily briefing has no manual push button; it only fires automatically, once per day per user (the `Analysis` row is upserted per date-keyed discriminator, so `markDone()` fires at most once for that day's row).

## Setup (one-time, out of band)

A bot is created in Telegram's @BotFather (`/newbot`), which also sets its name, @username, and avatar — the bot's distinct identity. The token lands in `.env` (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`), wired through [config/services.php](../../config/services.php). A token's webhook and `getUpdates` polling are mutually exclusive, so use **two bots**: a prod bot (webhook) and a separate test bot (polled locally). [TelegramClient](../../app/Services/Telegram/TelegramClient.php) wraps the Bot API over the `Http` facade.

- **Prod** registers the push webhook once via `telegram:set-webhook` ([SetWebhookCommand](../../app/Console/Commands/Telegram/SetWebhookCommand.php)). The `TELEGRAM_WEBHOOK_SECRET` may only contain `A-Z a-z 0-9 _ -` (1-256 chars) per Telegram's API; a base64 value (the `+ / =` from `key:generate`) is rejected with an opaque 400, so the command validates the charset up front and suggests `openssl rand -hex 32`.
- **Local dev** runs `telegram:listen` ([ListenCommand](../../app/Console/Commands/Telegram/ListenCommand.php)), a manual foreground long-poll (like `queue:listen`, never scheduled) that needs no public URL and feeds the same linking job the webhook does.

## Linking an account

Telegram has no OAuth, so the logged-in web session carries its identity through the bot. The Aku page ([ProfileController](../../app/Http/Controllers/ProfileController.php) `resolveTelegram()`) mints a signed deep-link token ([TelegramLinkToken](../../app/Services/Telegram/TelegramLinkToken.php), 60-min TTL) and renders a Telegram-branded "Hubungkan Telegram" button pointing at `t.me/<bot>?start=<token>`.

When the user taps Start, the update reaches [HandleTelegramUpdateJob](../../app/Jobs/Telegram/HandleTelegramUpdateJob.php) — via the webhook ([TelegramWebhookController](../../app/Http/Controllers/Telegram/TelegramWebhookController.php), CSRF-exempt and gated on the `X-Telegram-Bot-Api-Secret-Token` header) in prod, or `telegram:listen` in dev. It verifies the token (signature + expiry), then either links (storing the server-reported `chat_id`, replying with an account-naming welcome, and **consuming the token** so a leaked link can't be replayed), replies that the link is no longer valid without linking, or replies generically to garbage. `/stop` revokes. All reply copy is Temari-voiced in [TelegramReplies](../../app/Services/Telegram/TelegramReplies.php). The decision behind this flow is [[telegram-account-linking]].

## Preferences + disconnect

The Aku page ([Aku.tsx](../../resources/js/pages/Aku.tsx)) shows four switches (`notify_post_run`, `notify_weekly_recap`, `notify_monthly_recap`, `notify_daily_briefing`) and a "Putuskan" button once connected; [TelegramConnectionController](../../app/Http/Controllers/Telegram/TelegramConnectionController.php) persists the toggles and revokes on disconnect. The Telegram connect button keeps Telegram's brand mark and blue (not recolored), the way the Strava button is left as-shipped (see [[strava-connect]]).

## Sending

`AnalysisService::markDone()` fans out [SendTelegramNotificationJob](../../app/Jobs/Telegram/SendTelegramNotificationJob.php) for the notifiable types. The job resolves the user behind the analysis ([NotifiableAnalysis](../../app/Services/Telegram/NotifiableAnalysis.php)), then enforces: not the demo user, a non-revoked connection, the per-type opt-in on, and a `telegram_deliveries` unique-`analysis_id` claim so a Horizon retry never double-sends. The completion-hook side and its guards live in [[ai-pipeline]]. A manual push (see above) bypasses the opt-in and delivery-claim guards but still requires a non-revoked connection.

The message itself ([NotifiableAnalysis::format](../../app/Services/Telegram/NotifiableAnalysis.php)) is an emoji label (🏃 post-run / 📊 weekly / 🗓️ monthly / ☀️ daily briefing), the LLM narration verbatim, a one-line metrics summary (distance · duration · pace · HR, each dropped when null) for post-run, and a tap-through deep link to the activity, run-history, calendar, or dashboard page.

## See also

[[ai-pipeline]] · [[telegram-account-linking]] · [[strava-connect]] · [[profile]]
