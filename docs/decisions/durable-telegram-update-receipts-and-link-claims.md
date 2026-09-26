---
title: Durable Telegram update receipts and link claims
description: Telegram updates are durably deduplicated before dispatch, and one database claim atomically links a single-use token.
tags: [decision, telegram]
status: accepted
reviewed: 2026-09-26
supersedes: telegram-account-linking
code_refs:
  - app/Models/TelegramUpdateReceipt.php
  - app/Models/TelegramLinkTokenUse.php
  - app/Services/Telegram/TelegramLinkToken.php
  - app/Jobs/Telegram/HandleTelegramUpdateJob.php
  - app/Jobs/Telegram/SendTelegramLinkWelcomeJob.php
  - app/Http/Controllers/Telegram/TelegramWebhookController.php
  - app/Console/Commands/Telegram/ListenCommand.php
---

# Durable Telegram update receipts and link claims

**Status:** Accepted (2026-09-26), supersedes [[telegram-account-linking]]'s cache-backed token-use marker.

## Context

Telegram may retry webhook updates, and the development long-poll listener starts again at offset zero. A process-local offset or cache marker cannot guarantee that a replay is absorbed after a restart or cache flush. Two distinct `/start` updates can also race with the same valid token; checking token use before writing the connection leaves a window for both to link and welcome.

## Decision

Persist every accepted Telegram `update_id` in `telegram_update_receipts` before dispatching its job. The primary key makes the insert the atomic dedupe claim. The webhook acknowledges duplicates without dispatch; `telegram:listen` advances its local offset but skips already-received updates. If queue dispatch or synchronous listener handling throws, the intake path deletes the receipt and rethrows so a webhook retry or listener restart can try again. Receipts older than seven days are pruned daily.

Keep link tokens stateless and signed, but persist a unique SHA-256 token hash in `telegram_link_token_uses` when it is consumed. The claim and connection update run in one database transaction, so only one concurrent start can win and a failed link rolls back its claim. During rollout, token validation also reads the old cache marker so tokens consumed just before deployment stay spent through their remaining TTL. *(2026-09-27: that transitional cache read has been removed, since every token consumed before deployment has expired.)* The winning transaction dispatches a separate retryable welcome job after commit, so a Telegram send retry never repeats the link. Expired token-use claims are pruned daily.

## Consequences

- **Enables:** webhook retries and listener restarts are safe across process and cache loss; concurrent `/start` updates can create one link and one welcome.
- **Costs:** each accepted update and each successful link creates a small durable row until its scheduled prune.
- **Privacy:** receipts store only Telegram's numeric update ID and receive time; token-use rows store only a one-way token hash and expiry.

## See also

- [[telegram-notifications]] — the channel and account-linking flow
- [[telegram-account-linking]] — the original signed-token decision, retained for its token format and delivery-mode rationale
