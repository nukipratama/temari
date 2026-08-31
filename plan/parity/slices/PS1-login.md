# PS1 — Login

Login to prototype parity, per `plan/parity/reference.md`'s Login section.

## Goal

Adopt `LoginScreen.tsx`'s section list, order and treatment: the sky-to-leaf hero with its horizon
glow, the 440px auth card overlapping it, the hairline why-lists that become cards above 900px, the
kartu teaser row, a data & AI use disclosure, and the mono footer.

Login is one of the three screens that draws **no** `FaceIcon` (P10 — the others are Trends and
Settings). `PP2` removed the mascot from this page; nothing here adds one back.

## Files touched

| file | what |
|---|---|
| `resources/js/pages/Auth/Login.tsx` | the rebuild (519 lines reworked) |
| `resources/js/pages/Auth/Login.test.tsx` | +73 lines |
| `resources/css/app.css` | −65 lines: the `route-draw` keyframes and `.route-echo-path` rules that animated the old hero's route trace, dead once the prototype's hero replaced it |
| `resources/brand/grounds.json` | one panel registration |

## Blockers

None. `PP1` (shell, responsive model), `PP2` (mascot removal) and `PP3` (cuts) all landed first.

## Acceptance criteria

- [x] Section list, order and treatment match the prototype's Login screen.
- [x] Responsive model is the prototype's own — a single `min-[900px]` step, 760px column, and the
      auth card capped at **440px** rather than 760 (`Login.tsx:210`).
- [x] Feature list stacks below 900px and becomes `grid-cols-3` above it (`:117`).
- [x] CTA row becomes `inline-grid grid-cols-2` above 900px (`:126`).
- [x] Why-lists go from hairline-divided rows to bordered cards above 900px (`:272`, `:285`).
- [x] No `FaceIcon` anywhere on this page.
- [x] Lowercase copy treatment retained (scoped to Login by the `S1` copywriter ruling); small mono
      uppercase labels stay uppercase.
- [x] **`PP1`'s deferred reflow #2 — the headline size step — resolved**, with a recorded
      divergence: the token ladder carries it and no discrete step improves on it. Measurements and
      reasoning under Verification notes; the divergence is Open question 1.
- [x] Login chunk reported against the 160 kB gz budget (R6): **140.3 kB gz / 160 kB**.
- [x] Bottom padding matches the prototype (was Open question 2) — Login has no root wrapper, so
      the page's bottom pad is the footer's own `pb-7` plus the pitch wrapper's `pb-2`
      (`LoginScreen.tsx:152`, `:230`). Both ship byte-identical at `Login.tsx:111` and `:135`.
      `AppShell`'s nav clearance does not apply: Login is on `BareShell` with no chrome.

## Coverage delta

Measured on this worktree, `epic/mobile-ux-port` at `8415d1f7` vs the slice, same run
configuration (`npm run test:coverage`, full suite both times):

| | before | after |
|---|---|---|
| statements | 97.15% (3962/4078) | 97.15% (3968/4084) |
| branches | 90.49% (3229/3568) | 90.50% (3233/3572) |
| functions | 96.78% (1052/1087) | 96.78% (1055/1090) |
| lines | 97.54% (3769/3864) | 97.54% (3774/3869) |

Flat. The rebuild is a like-for-like swap in one page component: six statements and three
functions joined the denominator (`WhyList`, `WhyRow`, `KartuTeaser`, `DataUseDisclosure` replacing
`FeatureCard`, `RouteEcho`, `Hero`, `ConnectPanel`), all covered by the page's own suite, which
went from 12 tests to 14.

## Verification notes

**R6 is this slice's specific risk.** `bareLayout` is enforced framer-motion-free and capped at
160 kB gz, and `Auth/Login.tsx` is one of the four paths hardcoded in
`scripts/check-entry-chunks.mjs`'s `ROUTE_BUDGETS_KB`.

Two consequences shaped the implementation:

- The data & AI use disclosure is **hand-rolled rather than `ui/collapsible`**. That primitive is
  Base UI backed, and one Base UI portal in `BareShell`'s graph blows the budget.
- The removed `app.css` animation was already deliberately plain CSS rather than `lib/motion.ts`,
  for the same reason. It went because the prototype's hero replaced what it animated, not because
  the constraint changed.

Gate is `./vendor/bin/sail composer check` (single command since `C1`; it now runs exactly what CI
runs), plus `npm run test:coverage` and `npm run build && npm run check:chunks`.

**Entry chunks — nothing re-baselined.** P34 permits it; nothing needed it. Login came *down*.

| route | `PP1` | this slice | budget |
|---|---|---|---|
| entry | 111.7 kB gz | 111.5 | — |
| **Login** | 146.7 | **140.3** | **160** |
| Home | 226.9 | 205.1 | 240 |
| Runs/Show | 230.3 | 216.2 | 245 |
| Profile | 201.5 | 191.1 | 230 |

Login sits **19.7 kB under** its 160 kB ceiling. The −6.4 kB against `PP1` is the old hero's
`RouteEcho` SVG and the `SectionLabel`/`LegacyCard`/`PageHero` imports leaving the page's closure.
`toggle`, `toggle-group` and `collapsible` are all absent from this page — the disclosure is
hand-rolled precisely so they stay absent — and `bareLayout` remains framer-motion-free.

### Reflow #2, the headline size step — measured, not asserted

`PP1` deferred the prototype's `text-[34px]` → `@min-[900px]:text-[46px]`
([LoginScreen.tsx:97](../../../resources/brand/prototype/src/components/pages/LoginScreen.tsx))
because `PageHero` had no responsive size step. This slice's `h1` is bespoke, so any step was
available. Every step on the app's `--text-display-*` ladder was measured in a real browser against
the prototype's two values (deltas in brackets):

| variant | 834 | 899 | 900 | 1000 | 1150 | 1280 | 1536 |
|---|---|---|---|---|---|---|---|
| **prototype** | **34** | **34** | **46** | **46** | **46** | **46** | **46** |
| flat `display-sm` **(shipped)** | 34 (+0) | 36 (+2) | 36 (−10) | 40 (−6) | 46 (+0) | 51.2 (+5.2) | 52 (+6) |
| `display-sm` → `min-900:display-xs` | 34 (+0) | 36 (+2) | 28.8 (−17.2) | 32 (−14) | 36.8 (−9.2) | 41 (−5) | 42 (−4) |
| `display-sm` → `min-900:display-md` | 34 (+0) | 36 (+2) | 45 (−1) | 50 (+4) | 57.5 (+11.5) | 62 (+16) | 62 (+16) |
| `display-sm` → `min-900:display-lg` | 34 (+0) | 36 (+2) | 49.5 (+3.5) | 55 (+9) | 63.3 (+17.3) | 70 (+24) | 70 (+24) |

Flat `display-sm` ships. The reasoning:

- It is **exact (34px) at three of the five capture viewports** — `se`, `mobile` and `tablet`, all
  of which sit below the breakpoint — and hits 46px exactly at 1150px.
- `min-900:display-xs` has the smallest error at `desktop`/`wide`, but its clamp floor makes the
  headline **shrink 36px → 28.8px as the viewport crosses 900px**, precisely where the prototype
  jumps *up* by 12px. It inverts the reflow's direction; disqualified on sight of the measurement.
- `min-900:display-md` nails the breakpoint edge (45 vs 46) and then blows out to **+16px at both
  captured wide viewports**, which is worse than the status quo exactly where a reviewer diffs.

So the reflow's *intent* — 34px on phones and tablet, stepping up past the breakpoint — is
delivered by the fluid `clamp()` ladder the app adopted in `F2`/`F3`, and no discrete
`min-[900px]:` step on that ladder improves the fit. Per P2 this is token-nearest; the residual
+5.2/+6px at `desktop`/`wide` is recorded as Open question 1 rather than papered over with a
one-off literal, which P2 forbids and which would need a `check:palette` exception.

### Real-browser verification

Chromium against the built assets, `/login` at all five capture viewports plus both grounds,
asserting programmatically rather than by eye. Every number below is measured, not read off a class
string:

- hero padding **22px → 56px** across the breakpoint (prototype `px-[22px]` → `px-14`) ✓
- auth card `x=14` full-bleed below 900 with `margin-top −18px`; **440px wide, centred,
  `−30px`** above (prototype `mx-3.5 -mt-4.5` → `max-w-[440px] -mt-7.5`) ✓
- pitch and footer columns **760px, centred** above 900 ✓
- why-lists `display: flex` / `flex-direction: row` below 900 → **`grid`, `grid-template-columns:
  230.7px × 3`, rows `column`** above ✓
- headline italic, **weight 600**, cream ✓
- disclosure `aria-expanded="false"` on load ✓ (prototype's `Collapsible` is closed by default)
- **no `[data-face-icon]`** in the DOM at any viewport ✓ (P10)
- **no horizontal overflow** at any of the five viewports, in either ground: `scrollWidth ===
  innerWidth` and no element's box extends past it
- zero console or `pageerror` output on load

The hero stays on its fixed dark gradient under `data-theme="light"`, as the prototype's comment at
`LoginScreen.tsx:80-81` specifies.

## Open questions

1. **The headline runs +5.2/+6px over the prototype at `desktop`/`wide`** (51.2/52 vs 46). This is
   the residual of reflow #2 and the one place this screen knowingly leaves the prototype's number.
   It is the app's own fluid type ladder doing what `F2`/`F3` built it to do, and every discrete
   `min-[900px]:` alternative measured *worse* at the captured viewports — see the table under
   Verification notes. Reversing it is a one-utility change on `Login.tsx:176` if the call is that
   the breakpoint edge matters more than the wide viewports; `min-900:display-md` is the variant to
   reach for, at the cost of +16px at 1280 and 1536. Not taken unilaterally.

2. **The hero wordmark is `BrandMark`, not the prototype's inline lockup.** The prototype draws
   `TemariMark size={26}` + a 15px extrabold sans "temari"; `BrandMark` draws `size={28}` + a 20px
   mono "Temari" with the size as an inline style a `className` cannot override. Left alone
   deliberately: `BrandMark` is shared with the topbar lockup, so restyling it here would ripple
   into every screen `PS3`-`PS11` owns. Worth settling once, in whichever slice owns the topbar
   lockup, rather than twice.

3. **`dataUse.headline` is now unrendered on this page.** The prototype's disclosure heads its two
   blocks "what temari stores" and "before you take its advice", so `DataUseStatement::HEADLINE`
   ("Your data", Title Case) has no slot in Login's lowercase treatment. The prop is still sent —
   `SettingsController` shares the same payload shape and `StravaAuthTest` asserts it — so nothing
   was removed server-side. Only worth revisiting if Settings ever stops using that shape.
