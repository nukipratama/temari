---
title: Telegram notifications
description: Linking a Telegram account, the per-type notification toggles, and how post-run / weekly-recap narration is pushed to the bot.
tags: [feature, telegram]
status: living
reviewed: 2026-06-26
code_refs:
  - app/Services/Telegram/TelegramClient.php
  - app/Services/Telegram/TelegramLinkToken.php
  - app/Services/Telegram/NotifiableAnalysis.php
  - app/Jobs/Telegram/HandleTelegramUpdateJob.php
  - app/Jobs/Telegram/SendTelegramNotificationJob.php
  - app/Http/Controllers/Telegram/TelegramWebhookController.php
  - app/Http/Controllers/Telegram/TelegramConnectionController.php
  - app/Console/Commands/Telegram/SetWebhookCommand.php
  - app/Console/Commands/Telegram/ListenCommand.php
  - app/Http/Controllers/ProfileController.php
  - resources/js/pages/Aku.tsx
  - routes/web.php
---

# Telegram notifications

The first outbound channel: Temari pushes the most "alive" narration to the user's Telegram so the companion feels present without them opening the app. Two events notify, both keyed off the same chokepoint that finalizes any narration ([[ai-pipeline]]): the **post-run speech** (minutes after a Strava activity syncs) and the **weekly recap** (Monday morning). Each is an independent opt-in toggle; adding a third event is one entry in [NotifiableAnalysis](../../app/Services/Telegram/NotifiableAnalysis.php).

## Setup (one-time, out of band)

A bot is created in Telegram's @BotFather (`/newbot`), which also sets its name, @username, and avatar — the bot's distinct identity. The token lands in `.env` (`TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET`), wired through [config/services.php](../../config/services.php). A token's webhook and `getUpdates` polling are mutually exclusive, so use **two bots**: a prod bot (webhook) and a separate test bot (polled locally). [TelegramClient](../../app/Services/Telegram/TelegramClient.php) wraps the Bot API over the `Http` facade.

- **Prod** registers the push webhook once via `telegram:set-webhook` ([SetWebhookCommand](../../app/Console/Commands/Telegram/SetWebhookCommand.php)).
- **Local dev** runs `telegram:listen` ([ListenCommand](../../app/Console/Commands/Telegram/ListenCommand.php)), a manual foreground long-poll (like `queue:listen`, never scheduled) that needs no public URL and feeds the same linking job the webhook does.

## Linking an account

Telegram has no OAuth, so the logged-in web session carries its identity through the bot. The Aku page ([ProfileController](../../app/Http/Controllers/ProfileController.php) `resolveTelegram()`) mints a signed deep-link token ([TelegramLinkToken](../../app/Services/Telegram/TelegramLinkToken.php), 60-min TTL) and renders a Telegram-branded "Hubungkan Telegram" button pointing at `t.me/<bot>?start=<token>`.

When the user taps Start, the update reaches [HandleTelegramUpdateJob](../../app/Jobs/Telegram/HandleTelegramUpdateJob.php) — via the webhook ([TelegramWebhookController](../../app/Http/Controllers/Telegram/TelegramWebhookController.php), CSRF-exempt and gated on the `X-Telegram-Bot-Api-Secret-Token` header) in prod, or `telegram:listen` in dev. It verifies the token (signature + expiry), then either links (storing the server-reported `chat_id`, replying with an account-naming welcome, and **consuming the token** so a leaked link can't be replayed), replies that the link is no longer valid without linking, or replies generically to garbage. `/stop` revokes. All reply copy is Temari-voiced in [TelegramReplies](../../app/Services/Telegram/TelegramReplies.php). The decision behind this flow is [[telegram-account-linking]].

## Preferences + disconnect

The Aku page ([Aku.tsx](../../resources/js/pages/Aku.tsx)) shows two switches (`notify_post_run`, `notify_weekly_recap`) and a "Putuskan" button once connected; [TelegramConnectionController](../../app/Http/Controllers/Telegram/TelegramConnectionController.php) persists the toggles and revokes on disconnect. The Telegram connect button keeps Telegram's brand mark and blue (not recolored), the way the Strava button is left as-shipped (see [[strava-connect]]).

## Sending

`AnalysisService::markDone()` fans out [SendTelegramNotificationJob](../../app/Jobs/Telegram/SendTelegramNotificationJob.php) for the notifiable types. The job resolves the user behind the analysis ([NotifiableAnalysis](../../app/Services/Telegram/NotifiableAnalysis.php)), then enforces: not the demo user, a non-revoked connection, the per-type opt-in on, and a `telegram_deliveries` unique-`analysis_id` claim so a Horizon retry never double-sends. The completion-hook side and its guards live in [[ai-pipeline]].

The message itself ([NotifiableAnalysis::format](../../app/Services/Telegram/NotifiableAnalysis.php)) is an emoji label (🏃 post-run / 📊 weekly), the LLM narration verbatim, a one-line metrics summary (distance · duration · pace · HR, each dropped when null) for post-run, and a tap-through deep link to the activity or run-history page.

## See also

[[ai-pipeline]] · [[telegram-account-linking]] · [[strava-connect]] · [[profile]]
