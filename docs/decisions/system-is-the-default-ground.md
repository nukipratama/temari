---
title: The default ground is the device's, not dark
description: With no stored preference the app resolves its ground from prefers-color-scheme instead of falling back to dark; an explicit Settings choice still wins, and the two-ground architecture is unchanged.
tags: [decision, design]
status: accepted
reviewed: 2026-09-06
code_refs:
  - resources/views/app.blade.php
  - resources/js/hooks/useTheme.ts
  - resources/js/hooks/useSystemTheme.ts
  - resources/css/app.css
  - public/manifest.webmanifest
---

# The default ground is the device's, not dark

**Status:** Accepted (2026-09-06)

Supersedes the *default ground* half of [[dark-is-the-default-ground]] only. That note's two
authored grounds, its `data-theme` switch, its three-layer token model and the `-ink` tier
inversion all still stand exactly as written.

## Context

`F2` shipped two grounds and picked dark as the one an unconfigured visitor gets. The three
resolution sites each hardcoded that fallback — the pre-paint script in
[app.blade.php](resources/views/app.blade.php#L58), `readStored()` in
[useTheme](resources/js/hooks/useTheme.ts#L11), and the bare `html` rule in
[app.css](resources/css/app.css#L402) — so a phone in Light Mode opened a dark app and stayed
dark until its owner found Settings.

The live listener made that worse rather than milder. [useSystemTheme](resources/js/hooks/useSystemTheme.ts#L13)
returned early unless the stored value was literally `'system'`, so the majority case — no key at
all, because the user never opened Settings — got no OS updates either. The ground was pinned to a
default the user never chose and could not shake off by changing their device.

## Decision

**With no explicit choice, the ground is the device's.** Only a stored `'light'` or `'dark'` — a
deliberate tap in Settings — overrides `prefers-color-scheme`. A stored `'system'`, a first visit,
a stale value and unreadable storage are all one case now, resolved from the media query, and the
live listener treats them the same way ([useSystemTheme](resources/js/hooks/useSystemTheme.ts#L25)).
The no-JS CSS fallback is `color-scheme: light dark` rather than a pinned ground.

That collapse is the point: "no preference" had two spellings and they behaved differently, which
is what let a missing key mean *dark forever*. The Settings control is unchanged, and with nothing
stored it now shows **system** selected, which is what is actually happening.

## Consequences

- **Existing users who never opened Settings change ground.** Their key is absent, so they follow
  their device from the next load. Anyone who did pick light or dark keeps it, unchanged.
- **The installed PWA's splash stays dark.** [manifest.webmanifest](public/manifest.webmanifest#L10)
  holds one `background_color` and cannot vary by media query, so a light-ground launch flashes a
  dark plate before the app paints. This is the tradeoff already accepted for the splash images,
  which do vary by OS setting rather than by the stored key
  ([app.blade.php](resources/views/app.blade.php#L119)).
- **`theme-color` was already right.** Both metas are keyed by `prefers-color-scheme`
  ([app.blade.php](resources/views/app.blade.php#L74)), so Android's toolbar matched the OS even
  while the app itself defaulted to dark. It now agrees with the app for the unconfigured case and
  disagrees only for an explicit Settings override, which is the same limitation as before.
- **Dark is still the ground the app is designed on.** Nothing about authoring changes: both
  grounds are scored, both are painted, and a component still addresses colour by role.

## See also

- [[dark-is-the-default-ground]] — the two-ground architecture and the `-ink` tier decision, both
  still current; only its default-ground choice is replaced here.
- [[design-tokens]] — the ground-reactive semantic layer this resolves into.
- [[installed-app-shell]] — the splash and status-bar behaviour the manifest limitation lives in.
