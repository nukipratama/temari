---
title: Settings (Pengaturan)
description: The settings hub at /pengaturan — notification types and channels, the HR-zone entry, and account deletion — reached from the avatar menu.
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

`/pengaturan` is the one home for user settings. They were once scattered on Aku (`/profil`), then reached via a single row at the bottom of that page; the legacy `/settings` redirect still points at the real page ([routes/web.php](../../routes/web.php)).

**Navigation:** the entry point is the **avatar menu** ([UserMenu](../../resources/js/components/UserMenu.tsx)), directly above "Keluar". Because that menu is shared by [TopNav](../../resources/js/components/TopNav.tsx) and [MobileTopBar](../../resources/js/components/MobileTopBar.tsx), settings is one tap from every page on both mobile and desktop — rather than requiring a detour through Aku. `route('pengaturan')` → `/pengaturan` (GET). Named route: `pengaturan`.

Server entry is [SettingsController](../../app/Http/Controllers/SettingsController.php) (`__invoke`), rendering [Pengaturan/Index](../../resources/js/pages/Pengaturan/Index.tsx). It resolves the same Telegram payload the profile page used to (`resolveTelegram()`), including a fresh signed deep-link token per render.

## Sections

- **Notifikasi** — one section holding two groups, because the user's model is one topic with two questions rather than three unrelated ones:
  - *Apa yang dikirim* — the three channel-neutral per-type toggles (`post_run`, `weekly_recap`, `monthly_recap`), which gate Telegram and phone push alike. Full behaviour in [[telegram-notifications]].
  - *Ke mana* — Telegram and web push ([PushNotificationToggle](../../resources/js/components/PushNotificationToggle.tsx), rendered once a VAPID key is configured), each with a **mute** toggle once connected: off keeps the link and simply stops delivery, so re-enabling needs no re-auth. The destructive "Putuskan" / "Matikan" sits demoted beneath the row it belongs to. Plus the "Kirim notifikasi tes" button, which lives here rather than with the types because what it proves is that a channel can reach you — it has a 60s cooldown and a pending state, both shorter than the 5-minute per-recap send for the reasons in [[telegram-notifications]].
- **Lari · Zona HR** — a row linking to [[settings-hr-zones]] (`/pengaturan/zona`).
- **Akun · Hapus akun** — see below.

Every line is one primitive. [SettingsRow](../../resources/js/components/ui/SettingsRow.tsx) takes an optional `control` slot that replaces its chevron, so toggle rows and navigation rows share a layout instead of each inventing padding and type; a row carrying a control is never itself tappable, since a row that both navigates and holds a switch gives two different outcomes for taps a few pixels apart. The switch itself is [Toggle](../../resources/js/components/ui/Toggle.tsx), promoted out of this page once more than one place needed it.

The page opens with [PageHero](../../resources/js/components/ui/PageHero.tsx) like every other screen. It previously used a bare `<h1>`, which made it the one page that looked like it belonged to a different product.

It carries **no back affordance at all** — not in the page and not in the top bar. Pengaturan is one tap from the Aku tab and from the avatar menu on every page, so a breadcrumb would be chrome without a job. `Pengaturan/ZonaHR` is the exception and keeps one, since it is reachable only from here; see [[installed-app-shell]] for how the top bar decides.

## Account deletion

"Hapus akun" is the owner-facing way to release a Strava-account binding (one Strava account = one user, reused on every re-login). A confirmation modal guards against accidental deletion; confirming issues `router.delete('/akun')` → [AccountController](../../app/Http/Controllers/AccountController.php) `destroy()`, which deletes the user, logs them out, invalidates the session, and redirects to `/login` with a friendly flash.

Deleting the `User` row fires the model's `deleting` hook ([User](../../app/Models/User.php)), which revokes the linked Strava connection and writes a sync log — so the OAuth grant is released as a side effect of deletion, no separate disconnect step. The shared **demo** account can't be deleted (`AccountController` rejects `is_demo` with an error flash; the UI routes demo users through the demo-blocked modal instead).

## See also

[[profile]] · [[settings-hr-zones]] · [[telegram-notifications]] · [[strava-connect]]
