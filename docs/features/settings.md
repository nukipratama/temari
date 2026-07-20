---
title: Settings (Pengaturan)
description: The settings hub at /pengaturan — Telegram notification toggles, the HR-zone entry, and account deletion — reached from a single row on Aku.
tags: [feature, settings]
status: living
reviewed: 2026-07-13
code_refs:
  - app/Http/Controllers/SettingsController.php
  - app/Http/Controllers/AccountController.php
  - resources/js/pages/Pengaturan/Index.tsx
  - routes/web.php
---

# Settings (Pengaturan)

`/pengaturan` is the one home for user settings. Before, they were scattered on Aku (`/profil`); now Aku carries a single "Pengaturan" row that links here, and the legacy `/settings` + `/pengaturan` redirects point at the real page ([routes/web.php](../../routes/web.php)).

**Navigation:** `route('pengaturan')` → `/pengaturan` (GET). Named route: `pengaturan`.

Server entry is [SettingsController](../../app/Http/Controllers/SettingsController.php) (`__invoke`), rendering [Pengaturan/Index](../../resources/js/pages/Pengaturan/Index.tsx). It resolves the same Telegram payload the profile page used to (`resolveTelegram()`), including a fresh signed deep-link token per render.

## Sections

- **Notifikasi** — the three channel-neutral per-type toggles (`post_run`, `weekly_recap`, `monthly_recap`) plus a "Kirim notifikasi tes" button. The toggles gate Telegram and phone push alike. Full behaviour in [[telegram-notifications]].
- **Notifikasi HP** — the web-push subscribe/permission flow (`PushNotificationToggle`), shown once a VAPID key is configured.
- **Telegram** — the connect / disconnect flow. The demo account is guarded by the `block-demo-telegram` middleware and the front-door `DemoBlockedModal`.
- **Lari · Zona HR** — a row linking to [[settings-hr-zones]] (`/pengaturan/zona`).
- **Akun · Hapus akun** — see below.

## Account deletion

"Hapus akun" is the owner-facing way to release a Strava-account binding (one Strava account = one user, reused on every re-login). A confirmation modal guards against accidental deletion; confirming issues `router.delete('/akun')` → [AccountController](../../app/Http/Controllers/AccountController.php) `destroy()`, which deletes the user, logs them out, invalidates the session, and redirects to `/login` with a friendly flash.

Deleting the `User` row fires the model's `deleting` hook ([User](../../app/Models/User.php)), which revokes the linked Strava connection and writes a sync log — so the OAuth grant is released as a side effect of deletion, no separate disconnect step. The shared **demo** account can't be deleted (`AccountController` rejects `is_demo` with an error flash; the UI routes demo users through the demo-blocked modal instead).

## See also

[[profile]] · [[settings-hr-zones]] · [[telegram-notifications]] · [[strava-connect]]
