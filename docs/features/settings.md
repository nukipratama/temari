---
title: Settings
description: The settings hub at /settings — notification types and channels, the HR-zone entry, account deletion, and logout — reached via MeTabs from Profile.
tags: [feature, settings]
status: living
reviewed: 2026-08-19
code_refs:
    - app/Http/Controllers/SettingsController.php
    - app/Http/Controllers/AccountController.php
    - resources/js/pages/Settings/Index.tsx
    - resources/js/components/me/MeTabs.tsx
    - resources/js/components/UserAvatarLink.tsx
    - routes/web.php
---

# Settings

`/settings` is the one home for user settings. They were once scattered on Profile (`/profile`), then reached via a single row at the bottom of that page; the legacy `/pengaturan` redirect still points at the real page ([routes/web.php](../../routes/web.php)).

**Navigation:** one entry point. [UserAvatarLink](../../resources/js/components/UserAvatarLink.tsx), shared by [TopNav](../../resources/js/components/TopNav.tsx) and [MobileTopBar](../../resources/js/components/MobileTopBar.tsx), links the avatar straight to `/profile` from every page on both mobile and desktop — there is no dropdown any more. From there, the [MeTabs](../../resources/js/components/me/MeTabs.tsx) segmented nav, rendered atop Profile/Settings/Accessories alike, reaches Settings as a lateral tab. `route('settings')` → `/settings` (GET). Named route: `settings`.

Server entry is [SettingsController](../../app/Http/Controllers/SettingsController.php) (`__invoke`), rendering [Settings/Index](../../resources/js/pages/Settings/Index.tsx). It resolves the same Telegram payload the profile page used to (`resolveTelegram()`), including a fresh signed deep-link token per render.

## Sections

- **Notifications** — one section holding two groups, because the user's model is one topic with two questions rather than three unrelated ones:
    - _What gets sent_ — one channel-neutral master switch, **Keep me posted** (`notifications_enabled`), covering the post-run story, both recaps and the streak nudge, and gating Telegram and phone push alike. Full behaviour in [[telegram-notifications]].
    - _Where it goes_ — Telegram and web push ([PushNotificationToggle](../../resources/js/components/PushNotificationToggle.tsx), rendered once a VAPID key is configured), each with a **mute** toggle once connected: off keeps the link and simply stops delivery, so re-enabling needs no re-auth. The in-app inbox is not listed here: it is never muted, because muting it would lose history rather than spare an interruption ([[inbox-is-an-always-on-channel]]). The destructive "Disconnect" / "Turn off" sits demoted beneath the row it belongs to. Plus the "Send test notification" button, which lives here rather than with the types because what it proves is that a channel can reach you — it has a 60s cooldown and a pending state, both shorter than the 5-minute per-recap send for the reasons in [[telegram-notifications]].
- **Running · HR zones** — an inline expand/collapse disclosure, not a separate page any more; see [[settings-hr-zones]].
- **Your data** — the plain-language data-use statement, server-supplied from [DataUseStatement](../../app/Support/DataUseStatement.php) so the page and the public terms/privacy pages cannot word it differently. Why it says what it says: [[strava-data-compliance]].
- **The fine print** — links out to the four public documents in [[legal-pages]].
- **Account · Log out, Delete account** — logging out posts `/logout` directly from a row here (moved off the avatar link when it stopped opening a dropdown); delete account is covered below.

Every line is one primitive. [SettingsRow](../../resources/js/components/ui/SettingsRow.tsx) takes an optional `control` slot that replaces its chevron, so toggle rows and navigation rows share a layout instead of each inventing padding and type; a row carrying a control is never itself tappable, since a row that both navigates and holds a switch gives two different outcomes for taps a few pixels apart. The switch itself is [Switch](../../resources/js/components/ui/Switch.tsx), promoted out of this page once more than one place needed it. Renamed from `Toggle.tsx` in F3 — shadcn's own `toggle.tsx` primitive is an unrelated pressed-button control, and TypeScript won't allow both filenames to coexist differing only by case.

The page opens with [PageHero](../../resources/js/components/ui/PageHero.tsx) like every other screen. It previously used a bare `<h1>`, which made it the one page that looked like it belonged to a different product.

It carries **no back affordance at all** — not in the page and not in the top bar. Settings is a lateral MeTabs tab, one hop from Profile (itself one tap away via the avatar link), not a pushed screen, so a breadcrumb would be chrome without a job. HR zones is an inline disclosure on this same page now, not a pushed screen either — see [[installed-app-shell]] for how the top bar decides what gets a back button.

## Account deletion

"Delete account" is the owner-facing way to release a Strava-account binding (one Strava account = one user, reused on every re-login). A confirmation modal guards against accidental deletion; confirming issues `router.delete('/account')` → [AccountController](../../app/Http/Controllers/AccountController.php) `destroy()`, which deletes the user, logs them out, invalidates the session, and redirects to `/login` with a friendly flash.

Deleting the `User` row fires the model's `deleting` hook ([User](../../app/Models/User.php)), which revokes the linked Strava connection and writes a sync log — so the OAuth grant is released as a side effect of deletion, no separate disconnect step. The shared **demo** account can't be deleted (`AccountController` rejects `is_demo` with an error flash; the UI routes demo users through the demo-blocked modal instead).

## See also

[[profile]] · [[settings-hr-zones]] · [[telegram-notifications]] · [[strava-connect]]
