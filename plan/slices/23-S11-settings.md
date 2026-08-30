# S11 — Settings

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `B1` · **Status** merged ([#666](https://github.com/nukipratama/temari/pull/666), squashed as `cc3f5f5a`)

## Goal

Port `pages/Settings/Index.tsx` (499 L) + `components/settings/`. **Carries the appearance toggle
UI** — dark default, light/system reachable (decision 6), wired to the `localStorage` +
blocking-inline-script mechanism specified in `F2`/`F4`.

## What actually landed

**The Appearance card leads the page**, ahead of Notifications, matching the prototype's own
section order — a deliberate divergence from the shipped page's prior Notifications-first layout,
settled before implementation started (this is the slice's headline feature and nothing else lets a
user switch grounds by hand).

**`useTheme` is a new sibling hook to `useSystemTheme`, not an extension of it.** `useSystemTheme`
stays a narrow, side-effect-only OS-change listener with no public API; `useTheme`
(`resources/js/hooks/useTheme.ts`) owns the read/write side the Settings control needs: it mirrors
`app.blade.php`'s blocking-script resolution order exactly (explicit `light`/`dark` wins, `system`
resolves against `matchMedia('(prefers-color-scheme: dark)')`, anything else — first visit, storage
unavailable, a stale value — falls back to `dark`, decision 6's default), and its `setTheme` writes
`localStorage['temari-theme']` and stamps `document.documentElement.dataset.theme` +
`style.colorScheme` immediately so a tap is live with no reload. Both hooks read/write the same
`'temari-theme'` key as the single source of truth; `useSystemTheme` (already mounted once in
`AppShell`/`BareShell`) keeps `'system'` mode live across a later OS change while the tab stays
open, `useTheme` handles the explicit choice.

**`AppearanceCard`** (`resources/js/components/settings/AppearanceCard.tsx`) ports the prototype's
own `AppearanceCard` shape — a 3-way `ToggleGroup` (Light / Dark / System) — onto the already-adopted
shadcn `toggle-group` primitive, wired for real via `useTheme` instead of the prototype's local-only
`useState`. Icons render through the app's existing `Icon` component (which is already lucide-react
under an opaque `mdi:` key, per decision 16's already-completed swap — nothing here reaches for
`lucide-react` directly), so three new `ICON_MAP` entries were added to
`resources/js/components/ui/Icon.tsx`: `mdi:white-balance-sunny` → `Sun`, `mdi:monitor` → `Monitor`,
and `mdi:weather-night` reusing the already-imported `Moon`.

**The rest of the page's sections needed no restyle.** Decision 2 called for restyling
Notifications/Training preferences/Zones/Data-use/Legal/Account onto "the prototype's card/section
visual language and semantic design tokens." Reading the shipped page found that F3's mechanical
sweep had already converged Notifications, Data-use, Legal and Account onto the `Card` +
`SectionLabel` + `SettingsRow` idiom — which *is* this codebase's translation of the prototype's card
language (confirmed via `app.css`: `Card`'s `rounded-4xl` is 26px, documented as "the prototype's own
math arriving" at the same value as its `--radius-panel`). Only `HrZonesDisclosure` and
`TrainingPreferencesDisclosure` still carried a pre-sweep plain `rounded-xl border border-border/60`
wrapper with no `bg-card`/shadow. Both were brought onto the same look (`rounded-4xl border
border-border-strong bg-card shadow-e1`, inner divider `border-border-strong`) — a pure className
change, no logic touched. This is recorded here as a routine implementation-correctness call rather
than a fork requiring a decision: no visible section was left unstyled, no logic was rewritten, and
no cuts were made (matches the "no cuts were ruled for this page" instruction).

**Single-select guard on the toggle group.** The local `ToggleGroup` wrapper
(`resources/js/components/ui/toggle-group.tsx`) doesn't propagate base-ui's generic type parameter,
so `onValueChange` reports plain `string[]`; `AppearanceCard` narrows back to `ThemePreference` at
its own call site with a local type guard rather than widening the shared primitive for one caller.
Base UI's toggle group can also report an empty array when the currently-pressed item is clicked
again (single-select mode allows deselecting to zero); that case is ignored so exactly one option
always stays chosen — the control behaves as a true segmented control, never able to land on "no
ground selected."

**Verified against the acceptance bar by hand and by test**: `npm run build` then a hard reload on
each of the three settings (light/dark/system) confirmed no flash (the existing blocking script in
`app.blade.php` already covers this — S11 only had to make sure the value it reads is one this
control can actually write) and that a choice survives a reload without JS re-running. Both grounds
spot-checked across every section on the page (Appearance, Notifications, Training preferences/Zones
disclosures, Data-use, Fine print, Account) — the `bg-card`/`border-border-strong`/`shadow-e1` tokens
used by every card on the page are already ground-reactive (defined for both `[data-theme="dark"]`
and light in `app.css`), so no new dark-mode-specific styling was needed beyond the two disclosure
wrappers already covered above.

## Files touched

New: `resources/js/hooks/useTheme.ts` (+test), `resources/js/components/settings/AppearanceCard.tsx`
(+test).
Modified: `resources/js/pages/Settings/Index.tsx` (Appearance section added, leads the page),
`resources/js/components/settings/HrZonesDisclosure.tsx`,
`resources/js/components/settings/TrainingPreferencesDisclosure.tsx` (outer wrapper restyled onto
the `Card` look, no logic changed), `resources/js/components/ui/Icon.tsx` (3 new `ICON_MAP` entries:
`mdi:white-balance-sunny`, `mdi:monitor`, `mdi:weather-night`).

## Blockers

`F4`, `B1`. Both merged.

## Acceptance criteria

- [x] Appearance card leads the Settings page, ahead of Notifications (decision, not the shipped
      page's prior order).
- [x] The Light / Dark / System control switches the ground live, with no reload, by writing
      `localStorage['temari-theme']` and stamping `document.documentElement.dataset.theme` +
      `style.colorScheme` immediately (plan/README.md §9 item 6, verified by hand after
      `npm run build` — hard reload on each setting, no flash, choice persists).
- [x] `useSystemTheme`'s live OS-change listener for `'system'` mode composes correctly with the new
      control: both read/write the same `'temari-theme'` key, and `useTheme`'s resolution order
      exactly mirrors `app.blade.php`'s blocking script (explicit wins, `system` follows the OS,
      anything else defaults to `dark`).
- [x] Every other section on the page (Notifications, Training preferences, Zones, Data-use, Fine
      print, Account) renders correctly on both grounds — spot-checked directly; no logic dropped or
      rewritten, only two disclosure wrappers restyled onto the existing `Card` visual language.
- [x] No cuts on this page (per decision 2 / the ledger, which carries no Settings-specific entry).
- [x] UI chrome stays Title Case (`Light` / `Dark` / `System`, `Appearance`) — Settings is not Login,
      no lowercase treatment applied. No em-dashes introduced.
- [x] 1:1 test convention: both new files have a co-located test; no new `EXEMPT`/`TS_EXEMPT` entries.

## Coverage delta

Backend: unaffected (no PHP touched). Full suite still 3736/3736 passing, 11424 assertions
(`bin pest --parallel --no-tia`, 110038ms) — matches the pre-slice baseline exactly.

Frontend: 215 test files / 2085 tests passing (up from 213/2073 pre-slice — B4's last recorded
count — by the 2 new co-located test files this slice adds: `useTheme.test.ts` 7 cases,
`AppearanceCard.test.tsx` 5 cases). Coverage: **95.56% statements / 89.32% branches / 95.40%
functions / 95.92% lines**, vs the pre-slice baseline of 95.56% / 89.31% / 95.37% / 95.92% (branches
+0.01pp, functions +0.03pp, statements and lines unchanged — the new hook and component are both
fully exercised by their tests; the small net-positive delta comes from `useTheme.ts`'s and
`AppearanceCard.tsx`'s own coverage outweighing the untested branches the restyled disclosure
wrappers didn't add).

## Verification notes

`pest --group=structure` (38/38), full `bin pest --parallel --no-tia` (3736/3736, 11424 assertions),
`npx tsc --noEmit` clean, `npm run build && npm run check:chunks` green (Settings is not one of the
four hardcoded-budget routes; Login stays at 135.5 kB gz against its 160 kB cap, unaffected by this
slice), `npm run test:coverage` clean (215/215 files, 2085/2085 tests, coverage above), `check:palette`
clean (453 files scanned, zero off-token utilities), `php scripts/check-doc-citations.php` clean (run
directly per the ladder's rule for any card/token-touching slice, even though this slice added no new
translucent panel call site — `bg-card`/`border-border-strong`/`shadow-e1` are all solid semantic
tokens already registered, not alpha overlays, so `resources/brand/grounds.json` needed no
regeneration; confirmed by re-checking `grounds.mjs`'s tracked entries before and after).

**A resource-contention note for whoever runs this ladder next in a multi-worktree session**: this
worktree's Docker stack shares a single ~3.8 GiB memory pool with the two sibling `S1`/`S9` worktree
stacks (plus unrelated local projects) on this machine. Running `bin pest --parallel` concurrently
with `npm run test:coverage` in the same worktree twice produced a `WorkerCrashedException` (exit
137, SIGKILL) on an entirely unrelated test file each time — genuine OOM kills, not a real failure.
Frontend coverage run under the same concurrent load produced 20 spurious 5000ms test timeouts in
two files this slice never touches (`Activities/Calendar.test.tsx`,
`trends/panels/FitnessTrend.test.tsx`). Both ladder rungs came back fully green once re-run in
isolation (verified twice, the second time with full untruncated output captured to confirm `Test
Files 215 passed (215)` / `Tests 2085 passed (2085)` / zero `FAIL` matches, and the backend run's
exit captured directly rather than through a pipe). Run PHP and frontend coverage sequentially, not
concurrently, when a sibling worktree stack is also active.

Designer template §6 ("the theme toggle does not flash") verified specifically against this slice's
UI: `app.blade.php`'s existing blocking inline script (unchanged by this slice) already stamps
`data-theme` before first paint by reading the same `'temari-theme'` key this slice's control writes;
hard-reloaded on each of the three settings post-build with no visible flash on either ground.

## Open questions

None blocking. One thing intentionally left for a later slice: the Appearance card's own container
uses the plain `Card` component (`px-6 py-6`) rather than a bespoke wrapper, matching every other
section on this page — if a future design pass wants the appearance control visually distinct from
the rest of the settings list, that's a presentational change for whichever slice owns that call,
not a gap in this one.
