---
title: Installed app shell
description: What makes Temari feel native once it is on the iOS Home Screen — edge-to-edge status bar, launch image, top bar with back button, touch feel, edge-swipe back
tags: [feature, pwa]
status: living
reviewed: 2026-09-05
code_refs:
  - resources/views/app.blade.php
  - public/manifest.webmanifest
  - resources/js/hooks/useSwipeBack.ts
  - resources/js/components/MobileTopBar.tsx
  - resources/js/hooks/useBodyScrollLock.ts
  - resources/css/app.css
  - scripts/build-splash-screens.php
  - resources/brand/build-og.mjs
---

# Installed app shell

Temari is built to be added to the iOS Home Screen and run standalone. That mode
removes all browser chrome, which takes away things the app then has to provide
itself. This note covers the pieces that only matter once installed; the visual
language they use is in [[design-tokens]], and the shell's structure is in
[[dashboard]].

## The status bar is iOS's again

The app asks for `apple-mobile-web-app-status-bar-style: black`
([app.blade.php](resources/views/app.blade.php)). iOS paints the strip a solid
colour and the web view starts below it, so `env(safe-area-inset-top)` resolves
to **0** in standalone. Every consumer already floors that through `max()`, so
nothing depends on it being non-zero.

`black` and not `default` because dark is the default ground and #000 against
sky-deep is very nearly seamless; on the light ground it reads as a deliberate
band. The style cannot vary per colour scheme, so it is one choice for both.

### Five wrong turns, recorded so they are not repeated

Getting the top of the screen right took six attempts, and the first five were
all built on a premise that turned out to be false on-device:

- **#395** pinned `theme-color` to the header's cream. iOS does not use
  `theme-color` for the standalone status bar at all — only Android/Chrome does,
  for its toolbar.
- **#396** declared `color-scheme: light`, on the theory that a Dark Mode device
  made the UA render its own strip dark. Correct and worth keeping for form
  controls and scrollbars, but the band survived a fresh install.
- **#397 and #398** switched to `black-translucent` — the right mechanism at the
  time, since under `default` the strip was iOS-owned and unreachable from CSS —
  but then assumed the documented "forced white glyphs" behaviour and painted a
  dark backing for them: first a navy `MobileTopBar`, then a fading
  `StatusBarScrim`. On the iOS of the day the glyphs rendered **dark**, so that
  backing was not needed and was itself the ugly band being reported.
- **#399** therefore deleted the scrim and left the region unpainted. Correct
  until iOS 26.
- **#733** read Safari 26's new "chrome colour comes from the topmost element in
  the viewport" rule as the explanation for a grey haze that appeared across the
  top of every page, and reinstated an opaque `StatusBarScrim` to give iOS a
  colour to read. It changed nothing on device, which is the useful part: iOS
  was compositing **over** our content, not sampling a colour from it.

The actual cause is an iOS 26.1 regression — `black-translucent` stopped being
honoured, and installed web apps lost transparency behind the status bar. The
strip is no longer ours at any level, so the fix is to stop asking for it and
let iOS paint a solid bar. The scrim went with it; nothing paints there now.

The lesson worth keeping, now six attempts deep: **nothing about this strip can
be settled from the spec.** Ship the change and look at a phone.

`pt-[max(1rem,env(safe-area-inset-top))]` on the mobile top bar and on
`BareShell`, and `pt-[max(4rem,calc(env(safe-area-inset-top)+3rem))]` on the
content region, all keep their floor when the inset collapses to 0 — and still
clear the notch if a future iOS hands the pixels back.

## The mobile top bar, and its back button

[MobileTopBar](resources/js/components/MobileTopBar.tsx) is on **every** page.
#398 briefly scoped it to Me on the argument that a decorative brand mark and
an ambient sync chip did not justify permanent space on a phone. That
under-weighted the sync chip: Strava freshness is time-sensitive and `revoked`
is an actionable failure, so hiding it on the profile tab made a broken
connection invisible until the user visited a tab they had no reason to open
*because* nothing was syncing. It also quietly undid #396, which had moved
Settings and Log out into the avatar menu precisely so account actions were
reachable everywhere.

On a **pushed** screen the brand mark gives way to a back button — roots show
identity, pushes show a way out. Which screens count is decided in
[nav.ts](resources/js/lib/nav.ts), by Inertia page component rather than by URL
prefix: exactly five components carry the bottom nav (Today, Plan, Race, Trends,
History — Race lights the `plan` tab, being a sub-page of Plan), and **everything
else routed through `AppShell` is pushed**. That inversion is the parity
program's P6/P35, and it is what moved Profile, Settings, Inbox and activity
detail onto the back-chevron treatment.

Two details worth keeping:

- **Back is a real `<Link href>`, never `history.back()`.** A notification deep
  link opens `/activities/{id}` cold with nothing behind it, and `history.back()`
  would strand the user or exit the app. [useSwipeBack](resources/js/hooks/useSwipeBack.ts)
  remains the gesture equivalent.
- **One back affordance, at every width.** The bar is no longer `lg:hidden`, so
  the in-page `BackLink` that used to cover desktop on pushed pages is gone —
  the topbar chevron is the only way out, on every viewport.

`MobileTopBar` is selected by `data-testid` in tests rather than by tag, a habit
from when a second `<header>` (the deleted desktop `TopNav`) existed.

## Launch image

Without `apple-touch-startup-image` iOS holds a white screen until first paint.
The set is generated by
[build-splash-screens.php](scripts/build-splash-screens.php) (Imagick — the Sail
image ships no GD) into `public/splash/`, and linked per device size at
[app.blade.php#L108](resources/views/app.blade.php#L108). Regenerate after
changing `public/icon-512.png` or either ground's `--color-background`.

**Two sets, one per ground.** The images used to be a single cream set built on
a `#F5F0E4` that no longer exists as a token, from an icon that predates the v1
mark — so on the default dark ground a cold launch flashed a stale logo on a
colour the app never paints. Each device size now has a `dark` and a `light`
image, selected by an extra `prefers-color-scheme` condition on the same media
query. That follows the OS rather than the `temari-theme` key the head script
reads, so a user who overrode the ground in Settings still gets the OS-matching
image; the alternative is one fixed ground that is wrong for everyone on the
other one.

`public/manifest.webmanifest` has no equivalent mechanism — `background_color`
and `theme_color` are single values — so both pin the **default** ground
(`#0b1017`). The `theme-color` meta *can* vary, and ships one per ground.

[AppLayoutAssetsExistTest](tests/Unit/Architecture/AppLayoutAssetsExistTest.php)
expands the blade's device table across both grounds, so a missing image in
either set fails the suite.

## Social preview

`og:image` and `twitter:image` point at `public/og-default.png`, a static
1200x630 card generated by
[build-og.mjs](resources/brand/build-og.mjs) — colors from the same
`build-tokens.mjs` the stylesheet uses, mark geometry read straight out of
[temari-mark.svg](resources/brand/logo/temari-mark.svg), and text rasterised
through librsvg against the fonts the image installs. The per-card page ships
its own tags on top of these.

Nothing in a `<head>` fails loudly when its target is absent, so
[AppLayoutAssetsExistTest](tests/Unit/Architecture/AppLayoutAssetsExistTest.php)
resolves every `asset()` call in the layout and asserts the file is on disk. It
also counts the calls it resolved against the calls present, so a reference it
cannot parse fails the suite instead of dropping out of the sweep.

## Floating top bar (F4)

`MobileTopBar` is `absolute`, not `sticky`, and paints no background of its
own — content scrolls underneath it. What used to be a single translucent bar
is now a row of separate pill chips (the wordmark or back button on the left,
Strava/bell/avatar on the right), each carrying its own `bg-muted` backing so
it stays legible over whatever content happens to be behind it. `AppShell`
reserves the clearance with top padding on the content region instead of the
bar reserving flow space itself, mirroring how `BareShell` already pads for
the notch on standalone screens. `MobileBottomNav` followed the same move: a
floating `rounded-full` pill inset from the screen edges rather than a
full-width bar flush to them.

## Edge-swipe back

Standalone has no back button, so a detail page would otherwise be a dead end.
[useSwipeBack.ts#L43](resources/js/hooks/useSwipeBack.ts#L43), mounted once in
`AppShell`, translates the content region with the finger from a left-edge touch
and pops history past a distance or velocity threshold.

It is armed **only** when running standalone on a coarse pointer
([useSwipeBack.ts#L45](resources/js/hooks/useSwipeBack.ts#L45)); in a browser tab
Safari's own edge swipe already exists and a second handler would fight it. It
also bails when the gesture starts inside a horizontally scrollable element
([useSwipeBack.ts#L18](resources/js/hooks/useSwipeBack.ts#L18)) — strips, charts
and maps own their own sideways drags.

## Touch feel

Three things carry it, and all three are invisible on a desktop browser:

- **Press feedback.** `.pressable` ([app.css](resources/css/app.css)) is the only
  touch confirmation the app has, because the global tap highlight is turned off.
  It also scopes `touch-action: manipulation`, which drops the ~300ms
  double-tap-zoom wait on controls while leaving pinch zoom intact on content.
  A stray comment terminator silently deleted this entire rule from the compiled
  CSS between #395 and its fix — every control in the app was dead to the touch
  in production and nothing failed. `resources/js/test/cssIntegrity.test.ts`
  guards against a repeat, since CI runs vitest but never a bundle build.
- **16px form controls.** Safari force-zooms the page on focusing any control
  under 16px and does not zoom back out. Scoped to `(pointer: coarse)` so
  desktop keeps its denser type. This is load-bearing, not a style choice.
- **Scroll lock behind overlays.**
  [useBodyScrollLock](resources/js/hooks/useBodyScrollLock.ts), refcounted so
  overlapping overlays cannot unlock early. Applied to the modals, and to the
  history filter only below `lg`, where it is a sheet rather than a popover.

Tapping the tab you are already on scrolls to top instead of issuing a fresh
visit ([MobileBottomNav.tsx](resources/js/components/MobileBottomNav.tsx)).

## Deliberately absent

- **Haptics.** iOS Safari does not implement `navigator.vibrate`, so any haptics
  code would be dead on the primary target device.
- **Pull-to-refresh.** `overscroll-behavior-y: none` is set on purpose; the app
  is all-dynamic and uncached, so an accidental pull re-runs every controller.
  See the note in `resources/css/app.css`.
- **Page transition animations.** Removed in #396. A fade on a screen you just
  asked for costs time and says nothing, and the one shipped here started at
  opacity 0, so every navigation read as "old page → blank → fade in".
