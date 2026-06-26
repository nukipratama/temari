---
title: Telegram account linking via signed deep-link token
description: Linking a teman-lari account to Telegram uses a signed, TTL-bounded deep-link token (not a pairing code); prod receives via webhook, dev via long-poll.
tags: [decision, telegram]
status: accepted
reviewed: 2026-06-25
code_refs:
  - app/Services/Telegram/TelegramLinkToken.php
  - app/Jobs/Telegram/HandleTelegramUpdateJob.php
  - app/Http/Controllers/Telegram/TelegramWebhookController.php
  - app/Console/Commands/Telegram/ListenCommand.php
  - app/Http/Controllers/ProfileController.php
---

# Telegram account linking via signed deep-link token

**Status:** Accepted (documented 2026-06-25)

## Context

Telegram notifications ([[telegram-notifications]]) need each teman-lari account bound to a Telegram `chat_id`. Telegram has no OAuth: when a message reaches our bot, Telegram tells us the sender's `chat_id` but nothing about who they are on teman-lari. We have to carry the web identity through Telegram and verify it on return, without standing pending-link state to maintain, and without a leaked link silently linking the wrong account.

## Decision

We carry identity in a **signed, TTL-bounded deep-link token**, not a typed pairing code.

The logged-in Aku page mints a **compact HMAC-signed token** ([TelegramLinkToken](app/Services/Telegram/TelegramLinkToken.php), 60-min TTL, fresh per render): base64url of `user_id|expires_at` plus a truncated HMAC keyed on `APP_KEY`. It renders `t.me/<bot>?start=<token>`. On `/start`, [HandleTelegramUpdateJob](app/Jobs/Telegram/HandleTelegramUpdateJob.php) verifies the signature + expiry and branches: valid → link with the **server-reported** `chat_id`; signed-but-expired → reply "get a fresh link", no row written; tampered/malformed → generic reply. The token *is* the pending-link state, so there is no pending-codes table.

The token is deliberately **not** an encrypted blob: Telegram caps the `start` payload at 64 chars and allows only `[A-Za-z0-9_-]`, which `Crypt::encryptString` (a ~200-char base64 JSON envelope) blows past, so Telegram would silently drop it and linking would never fire. The compact signed form lands at ~50 chars.

The token is also **single-use**: on a successful link `TelegramLinkToken::consume()` records it spent in the cache (TTL = the token's own remaining lifetime, so the marker self-cleans), and `userId()` rejects a consumed token. This is the one piece of state the design keeps, and it lives in the cache (no table, auto-expiring) — it closes the replay window so a leaked link can't re-bind the account even before its TTL lapses.

Delivery is split by environment because a bot token's webhook and `getUpdates` are mutually exclusive: **prod** uses the webhook ([TelegramWebhookController](app/Http/Controllers/Telegram/TelegramWebhookController.php), authenticated by the `X-Telegram-Bot-Api-Secret-Token` header), **dev** uses `telegram:listen` long-poll ([ListenCommand](app/Console/Commands/Telegram/ListenCommand.php)) so local linking needs no public URL. Both feed the same job.

## Consequences

- **Enables:** one-tap linking with no pending-codes table and nothing to garbage-collect; the token is unforgeable (signed with `APP_KEY`, can't point at another `user_id`); a short TTL bounds replay of a leaked link; the `chat_id` is captured server-side from the real Telegram message, never user-supplied.
- **Costs:** the connect link expires (60 min) and must be re-minted from the Aku page; two bots (prod + test) to administer because webhook and poll can't share one token.
- **Gotchas:** the token is single-use, so a leaked link can't be replayed after the owner links; the residual window is only *before* the owner links (mitigated by the short TTL and the link only appearing on the owner's authenticated page). The consumed-marker lives in the cache, so a cache flush mid-window would let a token link twice — acceptable for a one-hour link. Rejected alternative: a typed 6-digit pairing code, which adds friction, needs a pending-codes table plus brute-force rate-limiting, and is strictly weaker.

## See also

- [[telegram-notifications]] — the feature this linking serves
- [[strava-connect]] — the OAuth connect flow this parallels
- [[ai-pipeline]] — the narration completion hook that triggers the sends
