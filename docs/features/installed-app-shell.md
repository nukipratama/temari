---
title: Installed app shell
description: What makes Temari feel native once it is on the iOS Home Screen — edge-to-edge status bar, launch image, top bar with back button, touch feel, edge-swipe back
tags: [feature, pwa]
status: living
reviewed: 2026-07-21
code_refs:
  - resources/views/app.blade.php
  - public/manifest.webmanifest
  - resources/js/hooks/useSwipeBack.ts
  - resources/js/hooks/useScrolled.ts
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

## The status bar is ours, and stays cream

The app runs `apple-mobile-web-app-status-bar-style: black-translucent`
([app.blade.php](resources/views/app.blade.php)). The web view extends up under
the status bar, `env(safe-area-inset-top)` resolves to a real value, and the
cream page fills the screen edge to edge — including behind the clock.

Nothing is painted over that region. **iOS renders the status glyphs dark
against it**, so cream is legible and no backing is needed.

### Three wrong turns, recorded so they are not repeated

The dark band above the header took four attempts, and the first three were all
built on a premise that turned out to be false on-device:

- **#395** pinned `theme-color` to the header's cream. iOS does not use
  `theme-color` for the standalone status bar at all — only Android/Chrome does,
  for its toolbar.
- **#396** declared `color-scheme: light`, on the theory that a Dark Mode device
  made the UA render its own strip dark. Correct and worth keeping for form
  controls and scrollbars, but the band survived a fresh install.
- **#397 and #398** switched to `black-translucent` — which *was* the right
  mechanism, since under `default` the strip is iOS-owned and unreachable from
  CSS — but then assumed the documented "forced white glyphs" behaviour and
  painted a dark backing for them: first a navy `MobileTopBar`, then a fading
  `StatusBarScrim`. On device the glyphs render **dark**, so that backing was
  never needed, and it was itself the ugly band being reported.

The lesson worth keeping: `black-translucent` is what hands us the pixels, and
on this app the correct thing to draw there is *nothing*. If a future change
makes the glyphs turn white (a different iOS version, a dark app surface at the
top), the fix is a backing behind the glyphs — but confirm that on a device
first rather than assuming it from the spec.

`pt-[max(0.75rem,env(safe-area-inset-top))]` on the Me top bar, and
`pt-[env(safe-area-inset-top)]` on the shell everywhere else, are what keep
content clear of the notch now that the web view runs edge to edge.

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
identity, pushes show a way out. Which screens count is an explicit map in
`MobileTopBar`, not something derived from `activeTabFromUrl`, for two reasons:

- `/calendar`, `/records`, `/accessories`, `/badges` and `/race` resolve to a tab
  too, but are reached through in-page tab strips, so they are siblings of their
  root rather than a stack, and keep the brand mark.
- `/settings` is deliberately absent from the map even though it is nested by
  URL. It's a lateral MeTabs tab reached from Profile (itself one tap away via
  the avatar link), not a pushed screen, so it behaves as a root. HR zones,
  once a standalone `/settings/zones` page and the one exception that kept a
  back button, is now an inline disclosure on Settings itself — see
  [[settings-hr-zones]].

Two details worth keeping:

- **Back is a real `<Link href>`, never `history.back()`.** A notification deep
  link opens `/activities/{id}` cold with nothing behind it, and `history.back()`
  would strand the user or exit the app. [useSwipeBack](resources/js/hooks/useSwipeBack.ts)
  remains the gesture equivalent.
- **Desktop keeps the in-page breadcrumb** where one exists. The bar is
  `lg:hidden`, so the [BackLink](resources/js/components/ui/BackLink.tsx) on
  pushed pages is hidden below `lg` rather than deleted — each viewport gets
  exactly one back affordance. Settings itself has no breadcrumb on either
  viewport.

`TopNav` is a separate component and also a `<header>`, which is why tests
select the mobile bar by `data-testid` rather than by tag.

## Launch image

Without `apple-touch-startup-image` iOS holds a white screen until first paint.
The set is generated by
[build-splash-screens.php](scripts/build-splash-screens.php) (Imagick — the Sail
image ships no GD) into `public/splash/`, and linked per device size at
[app.blade.php#L57](resources/views/app.blade.php#L57). Regenerate after
changing `public/icon-512.png`.

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

## Sticky header

`MobileTopBar` is `sticky` + translucent, with the hairline appearing only once
content is actually underneath it — driven by
[useScrolled.ts#L21](resources/js/hooks/useScrolled.ts#L21), which reads scroll
offset through `useSyncExternalStore` so a restored scroll position is already
correct on first paint.

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
