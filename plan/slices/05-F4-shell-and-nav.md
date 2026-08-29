# F4 — Shell + nav

**Wave** 1 · **Slot** main checkout · **Blockers** `F3` · **Status** in-review

## Goal

Rebuild `layouts/{AppShell,BareShell,appLayout}.tsx` on the prototype's topbar/bottomnav components.
Apply `lib/nav.ts` per the **ruled** resolution in [../ia.md](../ia.md): the app adopts the
prototype's **4-tab** bar (today / plan / trends / history), replacing the current 3-tab shape where
Plan nested under Today. `routes/web.php` does not need route changes for this — `/plan` and `/race`
already exist as routes, only their nav classification moves. Wire the appearance toggle (dark
default, light/system reachable — decision 6). Re-measure `scripts/check-entry-chunks.mjs`'s budgets
with the new shell in the graph.

This is the blocker for every wave-2b screen slice — it is the shared chrome all eleven screens sit
inside.

## What actually landed

`nav.ts` rewritten to the literal `plan/ia.md` spec: `TabId` gains `'plan'`, `today.prefixes`
narrows to `['/']`, `plan.prefixes` is `['/plan', '/race']`, and `icon` values switch from
`mdi:*` iconify strings to lucide component names (`Sunrise`/`CalendarCheck`/`LineChart`/`History`)
per decision 16's comment in the spec. `TopNav` needed **zero code changes** — it already consumed
`ITEMS` and `activeTabFromUrl()` generically, so the 4th tab and the reclassified `/plan`+`/race`
highlight fell out of the data change alone.

`MobileBottomNav` and `MobileTopBar` were rebuilt on the prototype's `AppBottomNav`/`AppTopbar`
components, not just re-themed: the bottom nav went from a full-width solid `bg-sky` bar to a
floating `rounded-full` frosted-glass pill (`bg-card/60`, `backdrop-blur-xl`, inset from the screen
edges) where the active tab grows and picks up a lime gradient fill; the top bar went from a sticky
bordered bar to a fully transparent, `absolute`-positioned row of separate pill chips (wordmark or
back button on the left; Strava sync, notification bell, avatar on the right), each carrying its own
`bg-muted` backing. Real functionality was preserved rather than copied from the prototype's static
mockup: real `<Link>` routing (not `href="#"`), `aria-current`, the scroll-to-top-on-active-tap
behaviour, the existing `BACK_TARGETS` pushed-screen back-button map, live Strava/notification/avatar
data (not the prototype's hardcoded badge count and avatar initial). `AppShell` and `BareShell` swap
their root `bg-cream-deep` for `bg-background` — value-identical to `bg-cream-deep` on the light
ground (`#f1f5f8` either way, confirmed via `getComputedStyle`), so zero visible change there, but now
genuinely ground-reactive on dark. `AppShell` reserves `pt-20` on the banners+`main` wrapper (mobile
only) to clear the now-floating top bar, mirroring how `BareShell` already pads for the notch.

New `useSystemTheme` hook, mounted in both shells: `app.blade.php`'s blocking script only resolves
`data-theme` once, before first paint, so a tab left open in `'system'` mode across an OS theme
change would otherwise stay stale until the next reload. The hook listens for
`prefers-color-scheme` changes and re-resolves live, but only when the stored preference is actually
`'system'` — an explicit light/dark choice is never overridden. This is infra only; there is still no
UI control to ever *set* `'system'` (that's `S11`/Settings, wave 2b, not started), so the hook is
currently unreachable in practice — it exists because `app.blade.php`'s own comment already commits
this slice to building it, ahead of the control that will use it.

`useScrolled` (the hook driving the old top bar's on-scroll hairline) had no consumer left once the
transparent chip treatment dropped the sticky+bordered design — deleted along with its test.

`scripts/check-entry-chunks.mjs`'s existing budgets (Login 160 kB, Home 240 kB, Runs/Show 245 kB,
Profile 230 kB) needed no adjustment: post-rebuild gzipped sizes moved by under 0.5 kB everywhere
(Login 134.8→135.0 kB), comfortably inside every budget already.

## Files touched

`resources/js/lib/nav.ts` (+ test), `resources/js/components/{MobileBottomNav,MobileTopBar}.tsx`
(+ tests), `resources/js/components/TopNav.test.tsx` (assertions only, no source change),
`resources/js/layouts/{AppShell,BareShell}.tsx` (+ `AppShell.test.tsx`), new
`resources/js/hooks/useSystemTheme.ts` (+ test), deleted `resources/js/hooks/useScrolled.{ts,test.ts}`,
`resources/brand/grounds.json` (new `card/0.6` panel registration, stale `cream-deep/0.85` dropped),
`docs/features/installed-app-shell.md` (rewrote the now-wrong "Sticky header" section, fixed a
stale safe-area class citation, dropped `useScrolled.ts` from `code_refs`).

## Blockers

`F3`, merged. The IA ruling landed in `plan/ia.md` during `L0` — no longer an open blocker, just the
spec implemented against.

## Acceptance criteria

- [x] `nav.ts` matches the literal `plan/ia.md` diff spec (4 tabs, `plan.prefixes`, lucide icon names).
- [x] `TopNav`, `MobileTopBar`, `MobileBottomNav` all render 4 tabs; `/race` and `/plan` both
      highlight `plan`, not `today`.
- [x] Mobile shell rebuilt on the prototype's floating-pill/floating-chip components, not just
      re-themed onto the old structural pattern (per the user's explicit choice on both forks).
- [x] `AppShell`/`BareShell` root background is ground-reactive (`bg-background`), not a fixed
      raw-palette literal.
- [x] The live `prefers-color-scheme` listener exists for `'system'` mode, scoped so it never
      overrides an explicit stored choice.
- [x] `grounds.json` regenerated by hand for the one new translucent panel call site; stale entry
      removed.
- [x] `check:chunks` green with no budget changes needed.
- [x] Full ladder green: structure, `tsc`, full frontend suite, full PHP suite, `test:coverage`,
      `check-raw-palette.mjs`, `check-doc-citations.php`.
- [x] `browser-review` sweep across mobile + se viewports: no broken icons, no clipped chips, no
      contrast problems, active-tab gradient visible everywhere it applies. Two apparent findings
      (an Inbox pill/content overlap, an anomalous capture width on 3 pages) both chased down and
      confirmed capture-tool artifacts, not real bugs — see Verification notes.

## Coverage delta

Frontend: 95.04%→95.11% functions, 95.46%→95.47% statements, 95.86%→95.85% lines (net flat, within
noise) — all three comfortably clear of the 95% floor. Backend: unaffected, no PHP touched.

## Verification notes

Full ladder run after implementation: `sail pest --group=structure --no-tia`,
`sail npx tsc --noEmit`, `sail npm run build && npm run check:chunks`, `sail npm run test` (full,
2030 tests), `sail bin pest --parallel --no-tia` (full PHP suite), `node scripts/check-raw-palette.mjs`,
`sail php scripts/check-doc-citations.php`, `sail npm run test:coverage`. All green.

**`browser-review` sweep, both mobile viewports (390×844 and 320×568), all 15 routes.** Two
parallel Sonnet inspection passes (one per viewport) surfaced two apparent findings, both
independently re-verified live rather than trusted from the screenshot alone (per the skill's own
"verify before acting" guidance) and both confirmed **not real**:

- A reported "1350px vs 1170px" width anomaly on dashboard/accessories/profile — checked against
  `audit.mjs`'s actual DOM measurement (`docW=390`, `overflow=false` on all three); not reproducible,
  likely a screenshot-file metadata misread.
- A reported "real, high-confidence" permanent overlap between the floating bottom-nav pill and
  Inbox's empty-state card at the `se` viewport — re-checked by scrolling the live page to its true
  bottom and measuring both elements' `getBoundingClientRect()`: a clean 70px gap between them, no
  overlap. The full-page screenshot's known `position: fixed`-freezes-at-capture-scroll-offset
  stitching artifact (the same class of artifact both inspection agents flagged and correctly
  discounted everywhere else) produced this one false positive despite the agent's own claim of
  having cross-checked it.

No real regressions found: every page's top-bar chips clear the notch/status-bar area with margin,
content clears the floating top bar (no text/cards poking up behind the chips), all four bottom-nav
icons render intact with no clipping, and the active tab's lime gradient is visible everywhere it
should be (Today/Plan/Trends all confirmed across both viewports).

## Open questions

- **`useSystemTheme` is currently dead in practice.** Nothing sets `localStorage['temari-theme']` to
  `'system'` yet — that control is `S11` (Settings, wave 2b, not started). The hook is tested in
  isolation and does nothing harmful mounted early, but it has no way to actually fire until `S11`
  ships. Not a defect, just noting the gap between "wired" and "reachable" explicitly, since it's the
  kind of thing that looks unused to a future reader who doesn't have this context.
- **The frosted-glass pill's legibility over bright content behind it** (flagged at low confidence by
  the `se`-viewport inspection pass, e.g. an avatar card's warm background showing faintly through the
  pill on Accessories/Race) reads as the intended `backdrop-blur` behaviour rather than a bug, but is
  worth a human glance rather than a definitive call from a screenshot.
- **Wordmark chip vs. back chip on drilled-into pages** (Accessories, Inbox, Profile's sub-tabs,
  Settings) — these all keep the wordmark per the existing `BACK_TARGETS` map (unchanged from before
  this slice: they're reached via in-page tab strips, not a navigation stack, so they're siblings of
  their root rather than pushes). Flagged by the inspection pass as worth confirming is still the
  intended IA, not a new decision this slice made.
