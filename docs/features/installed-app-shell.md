---
title: Installed app shell
description: What makes Temari feel native once it is on the iOS Home Screen — edge-to-edge status bar, launch image, top bar with back button, touch feel, edge-swipe back
tags: [feature, pwa]
status: living
reviewed: 2026-09-06
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

The app asks for `apple-mobile-web-app-status-bar-style: default`
([app.blade.php](resources/views/app.blade.php)) — the last value that gets a
plain system bar rather than a composited one. iOS 26.1 took this region away in
stages: under `black-translucent` it stopped honouring the translucency and
painted its own material over the strip, and `black` made that material lighter
without opting out of it. `default` costs the dark bar — the system's own reads
as a pale band above the app on the dark ground — and that is the deliberate
trade for it not being blurred.

The style cannot vary per colour scheme, so it is one choice for both grounds.

**This is only ever verifiable on a device.** The blur does not reproduce in a
desktop browser at any viewport, at any emulated safe-area inset, because it is
iOS's own art rather than anything the page draws. What *can* be reproduced
locally is the layout: Chromium's CDP `Emulation.setSafeAreaInsetsOverride`
makes `env(safe-area-inset-top)` resolve to a real value, so an iPhone 13
(390x844, inset 47px) can be driven headless in the Sail container and
screenshotted. Every layout question about this strip should be settled that way
before anything ships.

### Six wrong turns, recorded so they are not repeated

Every one of these was reasoned from the spec, and every one was wrong on device:

- **#395** pinned `theme-color` to the header's cream. iOS does not use
  `theme-color` for the standalone status bar at all — only Android/Chrome does,
  for its toolbar.
- **#396** declared `color-scheme: light`, on the theory that a Dark Mode device
  made the UA render its own strip dark. Worth keeping for form controls and
  scrollbars, but the band survived a fresh install.
- **#397 and #398** switched to `black-translucent` — the right mechanism at the
  time — then assumed the documented "forced white glyphs" behaviour and painted
  a dark backing for them: first a navy `MobileTopBar`, then a fading
  `StatusBarScrim`. The glyphs rendered **dark**, so that backing was itself the
  band being reported.
- **#399** deleted the scrim and left the region unpainted. Correct until iOS 26.
- **#733** read Safari 26's "chrome colour comes from the topmost element"
  rule as the cause of a new grey haze and reinstated an opaque scrim. It changed
  nothing, which was misread as proof that iOS composites over our content.
- **#734** concluded from that the region was unconditionally iOS's and deleted
  the scrim again. It was right that `black-translucent` was broken and wrong
  that the region is always iOS's — see the installer split below.

The blur that started all of this was never ours. That was finally established
not by another theory but by a control: the same stylesheet, at the same
viewport and the same emulated inset, renders the strip sharp in headless
Chromium, and the blur is present on device even with nothing behind the chips.

### The strip is not recoverable, so nothing textured goes in it

The blur is iOS's own and there is no opting out. `black-translucent` stopped
being honoured, `black` only lightened the material, and `default` changed
nothing — all three were tried on fresh installs from both Safari and Chrome.
It is a known WebKit regression in standalone PWAs with no published workaround;
the community technique (a fixed overlay above the viewport carrying a
`backdrop-filter`) is itself broken, its author noting that "the fixed overlay
positioned outside the viewport no longer renders under the status bar".

What works is not fighting the effect but removing what it feeds on. The blur
was only ever objectionable because *page text* passed through that strip, and
text was there because `MobileTopBar` floated: content scrolled underneath it and
showed through the gaps between the pills. **The bar no longer floats.** It sits
in normal flow, reserves its own space, and scrolls away with the page, so
nothing is pinned over that region and the only thing left up there is flat
background — which blurs to flat background.

`AppShell` therefore carries no clearance padding any more; the bar's own box is
the spacing. The bar shares `PageContainer`'s column rather than running
full-bleed, because in flow it sits directly above the content and chips pinned
to the screen edges of a 2560px display would read as belonging to nothing.

`StatusBarScrim` is gone for good. It covered only `env(safe-area-inset-top)`,
and across three attempts it never once demonstrably painted on device.

The check that finally made this tractable: the layout half reproduces locally.
Chromium's CDP `Emulation.setSafeAreaInsetsOverride` makes
`env(safe-area-inset-top)` resolve for real, so an iPhone 13 (390x844, inset
47px) can be driven headless in the Sail container and screenshotted. That is
what proved the stylesheet renders the strip sharp, and therefore that the blur
was never ours. The blur itself reproduces nowhere but a device.

The lesson, eight attempts deep: **nothing about this strip can be settled from
the spec.** Reproduce the layout locally, and search whether someone has already
hit it before theorising.

`pt-[max(1rem,env(safe-area-inset-top))]` on the mobile top bar and on
`BareShell` keeps its floor when the inset collapses to 0, and still clears the
notch if a future iOS hands the pixels back.

The top is not the only edge. In landscape the notch takes a *side*, so both
shells also carry `env(safe-area-inset-left/right)` on their outermost box
([AppShell.tsx](resources/js/layouts/AppShell.tsx),
[BareShell.tsx](resources/js/layouts/BareShell.tsx)) — one place rather than on
each gutter, since the top bar and `PageContainer` sit inside it and inherit the
inset. `MobileBottomNav` is `fixed` and therefore outside that flow, so its
track pads itself. `<main>`'s clearance for that nav adds
`env(safe-area-inset-bottom)` to its 7rem, so the last row of content clears the
home indicator the way the no-nav branch beside it already did.

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

**The mark is drawn, not composited from the app icon.** The first version of
this pasted `icon-512.png` onto the ground, and that file carries its own
`#171f28` plate ([temari-app-icon.svg](resources/brand/logo/temari-app-icon.svg))
— a visible square against sky-deep, and an inconsistent dark tile against
cream. The generator now rasterises
[temari-mark.svg](resources/brand/logo/temari-mark.svg) through Imagick's RSVG
delegate with a transparent background and composites the bare strokes, swapping
only the base-stroke colour per ground (cream on sky-deep, sky on cream — the
lead stroke is lime on both). Geometry still comes from the single SVG the
in-app `TemariMark` draws.

Because there is no plate, each ground takes the background it actually paints,
so first launch does not step colour on either.

`public/manifest.webmanifest` is a different consumer: Android composites the
*manifest* icon — plate and all — onto `background_color`, and it has no way to
vary by scheme. It therefore pins `#171f28`, the plate colour, which is what
keeps that composite seamless; `theme_color` is pinned to the dark value too. The
`theme-color` meta *can* vary, and ships one per ground.

[AppLayoutAssetsExistTest](tests/Unit/Architecture/AppLayoutAssetsExistTest.php)
expands the blade's device table across both grounds, so a missing image in
either set fails the suite.

The launch image only buys time; what happens *after* it is dismissed matters
just as much. The web fonts used to come from a Google Fonts stylesheet, so a
cold standalone start handed the first paint to a third-party origin on a
connection the app has no say over — the one moment where that cost is most
visible. They are self-hosted now
([fonts.css](resources/css/fonts.css)), fingerprinted into `/build` and served
from the same origin, immutable, as everything else. See [[design-tokens]].

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

What used to be a single translucent bar is a row of separate pill chips (the
wordmark or back button on the left, Strava/bell/avatar on the right), each
carrying its own `bg-muted` backing. The *floating* half of F4 is gone — see
above; the bar is in normal flow and scrolls away, which is what stopped content
passing under the iOS status bar. `AppShell`
no longer reserves clearance for it at all. `MobileBottomNav` keeps the floating
treatment — a `rounded-full` pill inset from the screen edges rather than a
full-width bar flush to them — because the bottom of the screen has no status
bar to collide with.

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

A tap on any *other* tab lights that tab at once. The highlight used to be
derived from `usePage().component` alone, so it did not move until the server
answered — a native app moves it on touch-up, and the wait was the loudest tell
the app had. The tapped tab is held as pending state and Inertia's `finish`
event hands the highlight back to whatever page the app actually landed on, so
a cancelled or failed visit cannot strand it. `aria-current` stays on the real
page throughout: the guess is visual only, and a screen reader is never told it
is somewhere it is not.

## Deliberately absent

- **Haptics.** iOS Safari does not implement `navigator.vibrate`, so any haptics
  code would be dead on the primary target device.
- **Pull-to-refresh.** `overscroll-behavior-y: none` is set on purpose; the app
  is all-dynamic and uncached, so an accidental pull re-runs every controller.
  See the note in `resources/css/app.css`.
- **Page transition animations.** Removed in #396. A fade on a screen you just
  asked for costs time and says nothing, and the one shipped here started at
  opacity 0, so every navigation read as "old page → blank → fade in".
