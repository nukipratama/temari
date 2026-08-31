# PP1 — Shell, nav and responsive model

**Program** prototype parity · **Slot** main (blocking; nothing runs concurrently) ·
**Blockers** `PP0` · **Status** in-review

## Goal

Carry P5-P9 and P31-P35: adopt the frozen prototype's own responsive model, replace the two nav
mechanisms the port had accumulated (a desktop `TopNav` bar and a `MeTabs` segmented strip) with
its push-screen model, and move Onboarding onto the chrome-free shell. Every screen's chrome
changes, so this slice owns the shell alone and no per-screen content rework.

## What actually landed

### The responsive model is the prototype's, not an invented one

The slice brief and `README.md` P5 both stated the prototype had **zero** responsive classes and
that content never reflows. That was false, and the slice stopped before writing code to say so.
The prototype uses **container queries** (`@min-[900px]:`, 93 utility instances across 24 lines in
all 11 screens) driven by `[container-type:inline-size]` on `PhoneFrame`'s screen wrapper, which a
`sm:`/`md:`/`lg:` grep does not find. P5, P31 and P32 were amended, and `PP0` produced
[reference.md](../reference.md) as the authoritative per-screen spec. This slice implements that
file, not P5's summary.

**Media query, not container query.** The prototype needs a container query because its screens
live inside a resizable device frame; the shipped app has no frame, and its layout wrapper is
viewport-width, so the two coincide. A media query is the right shape here for a concrete reason
beyond simplicity: `container-type: inline-size` establishes a containment context, and a
`position: fixed` descendant then positions against that container rather than the viewport — which
would break the fixed bottom-nav pill. The app therefore uses Tailwind's arbitrary media variant
`min-[900px]:`, matching the prototype's `@min-[900px]:` breakpoint value exactly.

**`PageContainer`** ([resources/js/components/ui/PageContainer.tsx:18](../../../resources/js/components/ui/PageContainer.tsx))
loses `max-w-page`, `max-w-page-2xl` and its four `sm:`/`lg:`/`2xl:` padding steps for a single
uniform treatment plus one breakpoint: the full mobile column with `px-4` below 900px, a centred
`max-w-[760px] px-6` above it. `--container-page` / `--container-page-2xl` stay in `app.css`
untouched — the operator console (`/devtools`, `/devtools/design`, `/ai-usage`) still uses them and
P20 leaves operator pages alone.

**Top padding** moves from a flat `pt-20` to `pt-[max(4rem,calc(env(safe-area-inset-top)+3rem))]`
with `min-[900px]:pt-6`, reproducing the prototype's `pt-16` → `pt-6`. The `max()` term is the one
addition: the app's topbar chips are `size-9` where the prototype's are `size-8`, so on a notched
device the bar is taller than 64px and a flat `pt-16` would tuck the first content row under it.
Above 900px the column narrows to 760px, the topbar's chips sit outside it, and the clearance is
free to drop — which is exactly why the prototype shrinks it there.

### Eleven wide-only reflows, eight carried

`reference.md` §1.2 enumerates eleven. Carried verbatim: Login's hero repad, its subhead widening,
its auth card (full-bleed below 900, `max-w-md` ≈ the prototype's 440px above), its "why the
comparison is fair" list going 3-up, its "what you get" list going 2-up, Today's plan-card head
`gap-4` → `gap-6`, and Profile's wide-only right-aligned hero block. Login's `WhyRow` card
treatment (#5) is already the app's base at every width, so its wide state needed nothing.

Three are recorded rather than implemented, each for a stated reason:

| # | reflow | why not carried |
|---|---|---|
| 2 | Login headline `text-[34px]` → `text-[46px]` | The app replaced literal type sizes with the `text-display-*` token ladder in `F2`/`F3`, and `PageHero` has no responsive size step. Adding one is a type-system change, not a layout change. `PS1` owns it. |
| 10 | Settings HR-zone bounds grid `grid-cols-2` → `grid-cols-4` | The prototype mocks three inputs in that grid; the shipped `HrZonesDisclosure` has **two** (Max HR, Resting HR). Four columns for two fields leaves half the row empty and each input a quarter-width. The base `grid-cols-2` **is** now carried (it was `grid gap-4`, single-column until `sm:`), which is the part that reads correctly with the real field count. |
| 11 | Settings `AccountActions` row `flex-col` → `flex-row` | The prototype draws a button pair; the app draws a `SettingsRow` list with `mdi:logout`. There is no row to turn sideways until `PS11` restructures the section. |

Per-screen **bottom** padding (`pb` 10/14/22/24 depending on screen, §1.1) is likewise left to the
`PS` slices: `AppShell` sets one clearance value for all nav screens and one for all pushed
screens, since it cannot vary per page without a second map, and the exact figure is a per-screen
content decision.

### Push-screen nav

[resources/js/lib/nav.ts](../../../resources/js/lib/nav.ts) now decides chrome by **Inertia page
component**, not URL prefix. `NavItem.prefixes` and `activeTabFromUrl()` are deleted with it: a
prefix list could not express "Race lights the `plan` tab" without also claiming every other
`/race`-adjacent route, and P35 inverts the default anyway.

- `navTabFor(component)` returns a tab for exactly five components — `Home`, `Plan`, `Race`,
  `Trends`, `History`, with **`Race` → `plan`** ([App.tsx:387](../../../resources/brand/prototype/src/App.tsx)).
- `backTargetFor(component)` returns `null` for those five and a back target for **everything
  else**, defaulting to Today. That is P35: `Collection/Accessories` is the only screen that
  currently lands on the default, and `W1` deletes it. `Activities/Feed` and `Activities/Calendar`
  are not affected — `History.tsx` renders them behind `?view=`, so they inherit History's chrome.
  Operator pages set no layout at all.

`MobileBottomNav` returns `null` on a pushed screen rather than being conditionally mounted, so the
decision lives in one place. `MobileTopBar` gains the per-screen trailing cluster the prototype's
five topbars specify: brand lockup + sync badge + bell + avatar on nav screens, back chevron + gear
+ bell on Profile, back chevron + bell on Settings, back chevron alone on Activity and Inbox.

**P32, the one recorded divergence.** The pill sits in a full-width fixed track and centres itself
at `max-w-[760px]`, matching the content column. The prototype gives its chrome no container query
at all — the pill is deliberately `inset-x-3.5` full-bleed while content narrows — so this is a
divergence against a real choice, not gap-filling. At 1536px the literal version spreads four items
across the whole viewport. The topbar, by contrast, **stays full-bleed as the prototype has it**:
that is what puts its chips outside the 760px column and lets the top clearance shrink to `pt-6`.

### Deleted

| deleted | why | call sites |
|---|---|---|
| `components/TopNav.tsx` + test | P9, reverting `V0` fork 5 ([#678](https://github.com/nukipratama/temari/pull/678)) | `AppShell` |
| `components/me/MeTabs.tsx` + test | P8 | `Profile`, `Settings/Index`, `Collection/Accessories` |
| `Runs/Show`'s `hidden lg:inline-flex` `BackLink` | The topbar chevron now shows at every width; this was the desktop half of a two-affordance split | `Runs/Show` |
| `nav.ts`'s `prefixes` + `activeTabFromUrl()` | Orphaned by the component-keyed model | `MobileBottomNav`, `TopNav` |

`SectionTabs` survives — `race/PlanRaceTabs.tsx` still uses it. `HeaderBrandMark` survives and
needed no change: `V0` fork 2 had already given it the prototype's `TemariMark` ring geometry
verbatim.

### Onboarding

Moves to `bareLayout` (P34), losing the topbar, bottom nav and banner stack. Its `PageContainer`
takes `pt-16 min-[900px]:max-w-[520px]` — the 520px column, and the top pad that **does not
shrink** at 900px, which `reference.md` §1.1 flags as the one screen where `pt-16` is a design
choice rather than topbar clearance.

### The P5 sweep

Collapsed desktop-only layout the prototype does not specify, across 24 files: the six banner
wrappers (`lg:px-8` + `max-w-page-2xl` → `min-[900px]:px-6` + `max-w-[760px]`), `HeroPanel`,
`WeekPlanWidget`, `TodaySession`, `KpiTile`, `VitalChips`, `EmptyRunsState`, `NarrationHeadline`,
`FitnessTrend`, `CtlTrendChart`, `SplitsTable`, `LapsGraph`, and the desktop grids on `Home`,
`Plan`, `Race`, `Profile`, `Runs/Show`, `Onboarding` and `Login`.

`Activities/Calendar` needed the largest single pass (39 `lg:` utilities → 0). That was not purity:
its `lg:` layer widened the calendar for a 1440px page (`lg:grid-cols-[6rem_repeat(7,1fr)]`,
`lg:min-h-[140px]`, `lg:p-3`), and with the column now capped at 760px those steps would fire at
1024px viewport inside a 760px column and actively cramp it. Content the `lg:` layer revealed only
on desktop (`hidden lg:block` week notes, per-day pace) now shows at every width, since the column
has room for it.

Deliberately **not** swept: `Kartu` and `shareCard` (`PP2` owns the share card, and client/server
must stay pixel-identical), `CardReveal`, `AccessoryUnlockModal`, `FeaturedCardHero`,
`SeasonStreakPanel`, `UnlockToast` and the five Trends panels `P25` cuts — all are `PP3` deletions,
and collapsing a file that is about to be deleted is churn. `Collection/Accessories`' internals and
the operator pages are untouched per the ledger and P20.

## Files touched

**Shell / nav**: `layouts/AppShell.tsx`, `lib/nav.ts`, `components/MobileTopBar.tsx`,
`components/MobileBottomNav.tsx`, `components/ui/PageContainer.tsx` (+ their tests).
**Deleted**: `components/TopNav.tsx`, `components/me/MeTabs.tsx` (+ their tests).
**Sweep**: 6 banners, `HeroPanel`, `HrZonesDisclosure`, `WeekPlanWidget`, `TodaySession`,
`KpiTile`, `VitalChips`, `EmptyRunsState`, `NarrationHeadline`, `FitnessTrend`, `CtlTrendChart`,
`SplitsTable`, `LapsGraph`, `pages/{Home,Plan,Race,Profile,Inbox}`, `pages/Runs/Show.tsx`,
`pages/Auth/Login.tsx`, `pages/Onboarding/Index.tsx`, `pages/Settings/Index.tsx`,
`pages/Collection/Accessories.tsx`, `pages/Activities/Calendar.tsx`.
**Generated**: `resources/brand/grounds.json` (drops `TopNav`'s `card/0.6` panel registration).
**Docs**: `docs/features/{profile,settings,notification-inbox,targets-accessories,installed-app-shell}.md`,
`docs/architecture/frontend-architecture.md`.

## Acceptance criteria

- [x] One centred column at every width, `760px` above 900px, `520px` for Onboarding, the mobile
      column below — verified in a real browser, not just in class strings.
- [x] Exactly five screens carry the bottom nav; Race lights `plan`.
- [x] Activity, Inbox, Profile and Settings render a back chevron and no bottom nav.
- [x] Login and Onboarding render no chrome at all.
- [x] Bell → Inbox and avatar → Profile on the brand topbar; gear → Settings on Profile's.
- [x] Back goes to a fixed parent (P33), as a real `<Link href>`, never `history.back()`.
- [x] The pill is column-width and centred, not viewport-spanning.
- [x] `TopNav` and `MeTabs` deleted with their tests; no `EXEMPT` entry added.
- [x] `grounds.json` regenerated; `DesignTokenContrastTest` green.
- [x] Docs that the change made wrong are fixed in the same PR.

## Coverage delta

Measured on this worktree, `origin/epic/mobile-ux-port` vs the slice, same run configuration:

| | before | after |
|---|---|---|
| statements | 95.51% (4583/4798) | 95.51% (4579/4794) |
| branches | 89.25% (3796/4253) | 89.21% (3797/4253→4256) |
| functions | 95.54% (1244/1302) | 95.52% (1239/1297) |
| lines | 95.97% (4341/4523) | 95.97% (4337/4519) |

Flat. Two fully-covered components left the denominator with their tests; `nav.ts` gained more
tests than it lost lines.

## Verification notes

**Entry chunks — nothing re-baselined.** P34 permits it, but no budget needed moving: every route
came in at or slightly under its previous number, so raising one would have been noise.

| route | before | after | budget |
|---|---|---|---|
| entry | 111.7 kB gz | 111.7 kB gz | — |
| Login | 146.7 | 146.7 | 160 |
| Home | 227.1 | 226.9 | 240 |
| Runs/Show | 230.9 | 230.3 | 245 |
| Profile | 203.0 | 201.5 | 230 |

Onboarding moving from `AppShell` to `BareShell` was the one change with real chunk-graph risk
(`R6`: one Base UI portal in `BareShell`'s closure blows Login's 160 kB budget). Login is unmoved
at 146.7 kB, so the two pages did not end up sharing a chunk.

**Ladder**: `pest --group=structure` green (38) · full `pest --parallel` green (3699 tests, 11140
assertions) · `tsc --noEmit` clean · `eslint` clean · vitest 2066/2066 · `npm run build` +
`check:chunks` green · `check:palette` green (461 files, zero off-token utilities) ·
`check-doc-citations.php` run directly, green. `composer check`'s rector step exceeded Composer's
300s process timeout on this machine; no PHP source file is touched by this slice, and pint and
phpstan both passed inside that same run.

**Visual check** — real Chromium against the built assets, 2 grounds × 2 viewports (390px, 1536px)
× 4 pages (Today, Profile, Settings, Inbox), 16 page-loads, asserting programmatically rather than
by eye: ground applied, no horizontal overflow, bottom nav present/absent per screen, brand mark vs
back chevron per screen, gear present on Profile, column ≤ 762px and centred to within 2px, pill
column-width and centred, first content row below the topbar's bottom edge. **0 failures**, and no
console or page errors. Measured separately at the *end* of each page after scrolling to the
bottom, on all six `AppShell` screens at both viewports: 68px of clearance above the pill on nav
screens, 52px above the viewport edge on pushed screens — no screen ends underneath its chrome. The
screenshots were reviewed by eye as well; the only flag raised was content visible *through* the
frosted pill mid-scroll, which is the prototype's `backdrop-blur-xl` design, not an overlap.

## Open questions

1. **`MobileTopBar` / `MobileBottomNav` are now misnamed** — they are the only chrome at every
   width, and nothing about them is mobile-specific any more. Renaming them was deliberately not
   done here: `grounds.json` is keyed by exact file path (`R4`), and a rename during the one slice
   that every other parity slice branches from buys nothing but conflicts. Worth doing in `W2`.
2. **Per-screen bottom padding** (§1.1's `pb` column) is unimplemented, by design — the `PS` slices
   own it. If it turns out to matter uniformly, `AppShell` needs a second component-keyed map.
3. **Reflow #10** (Settings' HR-zone grid) becomes portable verbatim if `PS11` adds the third and
   fourth zone-bounds inputs the prototype draws.
4. `CLAUDE.md` still says the app is "light-mode only: `.dark` is never applied". Two grounds
   shipped in `F2` and the appearance toggle in `S11`; the instruction is stale. Out of this
   slice's scope, but it primes every agent that reads it.
