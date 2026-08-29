# F3 — Mechanical sweep

**Wave** 1 · **Slot** main checkout · **Blockers** `F2` · **Status** in-review (PR pending)

## Goal

Migrate ground-dependent utilities per the table in the frozen plan's "what the sweep actually
changes" section. **Not** a global rename — fixed-identity values (`--mood-*`, `--rarity-*`,
`--color-strava-orange`, `--color-horizon` as accent fill, `--color-ink-on-sky`) are untouched.

## What actually landed

Three script-generated commits, not four — the icon swap and the primitive swap turned out to be
architecturally distinct enough that neither fit the plan's original shape exactly. Each commit's
own message has the full reasoning; this is the summary.

**Commit 1 — ground-dependent utility migration**
(`plan/codemods/01-migrate-ground-utilities.mjs`). Mechanical, as planned: `bg-surface-*` →
`bg-{card,popover,muted,accent,background}`, `text-ink*` → `text-{foreground,text-2,text-3}`,
`border-line*` → `border-border*`, `font-display` → `font-serif`, across `resources/js`. Also
rewrites `resources/brand/grounds.json`'s `paper` classification and `panel` registry to match —
R4's "worst coupler" fired exactly as warned; the panel keys and their `text` token entries had to
move in lockstep with the class rename or the contrast gate reads stale tokens. Hand-authored
alongside the script (not its output): `resources/css/app.css` renames the `--font-display` theme
var to `--font-serif` and repoints five composite typography utilities (`.text-prose`, `.text-stat`,
`.text-stat-sm`, `.text-meta`, `.voice`) at the new ground-reactive tokens — they were still pinned
to the non-reactive raw palette, which would have made Temari's voice text and every KPI value
unreadable on the dark ground; `resources/views/app.blade.php`'s `<body>` likewise moved off the
fixed `bg-surface`/`text-ink`.

**Commit 2 — icon runtime swap** (`plan/codemods/02-swap-icon-runtime.mjs`). `@iconify/react` is
gone (`lib/iconBundle.ts`, `scripts/build-icon-bundle.mjs`, the `prebuild`/`predev` hooks, and the
`@iconify/react`/`@iconify/json` dependencies all deleted, per decision 16). But the swap is a new
`resources/js/components/ui/Icon.tsx` wrapper that keeps the existing `<Icon icon="mdi:xxx" .../>`
call-site API unchanged, rather than retyping every consumer's `icon: string` prop to a lucide
component reference. That prop crosses into data three ways a pure rename can't reach cleanly:
`nav.ts`/`Devtools`/`Settings` config arrays, `StravaSyncBadge`'s status→icon map, and — the one
that actually forced this design — `UnlockFlash.icon`, a string `GrantEligibleUnlocksAction` and
`InboxController` send from `config/temari_unlocks.php`. The wrapper's `ICON_MAP` covers every
`mdi:*` key actually in play, gathered from both `resources/js` and that config file — which turned
up eight production icon keys (`tshirt-crew`, `lingerie`, `shoe-sneaker`, `blur`, `medal`,
`medal-outline`, `trophy`, `bandage`) the old `iconBundle.ts` never bundled at all, meaning those
accessory unlocks were silently rendering no icon before this change. Strava and Telegram keep
their exact brand-mark paths, rendered inline rather than through lucide.

**Commit 3 — primitive vendoring + call-site swap**. Vendors `card`/`badge`/`button`/`toggle`/
`toggle-group` from the frozen prototype (`collapsible` not yet used anywhere, so not vendored).
Swaps call sites onto them **only where a call site is a faithful match** — see Deviations below
for why two of the plan's six pairings and a large minority of the other four's call sites stay on
the legacy component instead. `Card.tsx`/`Toggle.tsx` are renamed to `LegacyCard.tsx`/`Switch.tsx`
(`plan/codemods/03-rename-legacy-primitives.mjs`) rather than deleted: TypeScript refuses two files
in one directory differing only by case, which `Card.tsx`/`card.tsx` and `Toggle.tsx`/`toggle.tsx`
now would be, and the same holds on any case-insensitive filesystem (macOS included).

`format` + `eslint --fix` ran as part of each commit above rather than as a separate fourth pass —
there was no reason to defer it. `resources/js/lib/cn.ts` needed no update: F2 didn't rename any
`--text-*` typography token, only the ground-reactive color layer, so its `font-size` group list
(the thing that would break on a rename) is untouched.

## Deviations from the original plan, logged

Two of the plan's six primitive pairings don't hold up on reading the actual components, not just
their names:

- **`Toggle` ↔ `toggle`.** `Toggle.tsx` is a `role="switch"` on/off control (Settings, push
  notification prefs). shadcn's `toggle.tsx` is a pressed/unpressed button — the segmented-control
  building block, a completely different interaction. Swapping would replace real switch behaviour
  with the wrong control. `Toggle.tsx` is kept (renamed to `Switch.tsx` for the case-collision
  reason above), untouched otherwise.
- **`SectionTabs` ↔ `toggle-group`.** `SectionTabs.tsx` is an Inertia `<Link>`-based nav strip —
  real page navigation, with scroll-fade masking, auto-scroll-into-view, and a badge count.
  `toggle-group` is a stateful client-only button group with no `href`/navigation support at all.
  Forcing this would either break deep-linking/back-button behaviour or require re-implementing
  most of `SectionTabs`' custom behaviour around a `ToggleGroupItem` render-prop, for no benefit.
  Left entirely untouched. Flagged here as an open question rather than a firm decision-table
  amendment — a later slice may find a real use for `toggle`/`toggle-group` (both stay vendored)
  that isn't this one.

The other four pairings are real, but only for the subset of call sites whose tone/variant is a
genuine visual match:

- **`Card`** (34 call sites, 30 swapped). shadcn's `card.tsx` has one visual treatment, no
  tone prop, and is a fixed `<div>`. `Card.tsx`'s `sky`/`onSky`/`empty` tones (dark hero panels,
  cards mounted on one, dashed empty state) have no equivalent, and neither does any
  `as="section"/"article"/"li"` call site — `card.tsx` cannot render as anything but a `<div>`, and
  those are real semantic-HTML/accessibility choices, not decoration. ~21 call sites across 14
  files stay on `LegacyCard.tsx` for these reasons (2 of the 21 files import both, under an
  explicit `LegacyCard` alias). The 30 swapped sites translate their old `padding` prop to an
  explicit `px-N py-N` className override, since `card.tsx` only applies `--card-spacing` as `py`
  on the root — `px` lives on `CardHeader`/`CardContent`/`CardFooter`, which none of these call
  sites use (children go directly into `<Card>`). A couple of sites conditionally added
  `border-{color}` to signal state (today's plan day in `Plan.tsx`, a completed goal in
  `GoalCard.tsx`) riding on `Card.tsx`'s built-in `border`; added an explicit `border` alongside
  since `card.tsx` has none by default (ring only, not a border).
- **`PillButton`** (23 call sites, 7 swapped). Only `tone="horizon"` is a solid match for
  `Button`'s `default` variant — both solid-fill, and `--color-primary` is horizon since F2.
  `tone="sky"` has no `Button` equivalent (no solid dark-navy variant). `tone="ghost"`/`"outline"`
  are both visually closer to `Button`'s `outline` variant than to `Button`'s own `ghost` (which has
  no border until hover, unlike `PillButton`'s always-bordered ghost) — collapsing two into one
  loses a visual distinction the app currently draws deliberately, so left on the legacy component
  rather than guess.
- **`Chip`** (9 call sites) and **`PillLink`** (3 call sites): zero swaps. `Chip`'s tones are soft
  alpha-tinted labels (e.g. `bg-horizon/[0.18]`); `Badge`'s variants are solid fills. Even the
  "same hue" pairing (`Chip` `tone="horizon"` vs `Badge` `variant="default"`) reads as a different
  component, not a naming difference — a tinted pill and a solid pill are not interchangeable.
  `PillLink` shares `PillButton`'s tone vocabulary and the same problem, and no real call site uses
  `tone="horizon"` (the one tone that would have been a fair match), so nothing to swap.

`resources/brand/grounds.json` also needed: classifying the 4 new backgrounds shadcn's primitives
paint (`primary`, `secondary`, `destructive`, `input`) and the false-positive `clip-padding` (a
`background-clip` utility on `button.tsx`'s base classes, not a colour); registering the
alpha-tinted panels they paint (hover/dark-mode states). Three of those — badge/button's
`bg-destructive/{10,20,30}` under `text-destructive` — measure under 4.5:1 against this app's
darkest paper grounds; pinned in the `belowAa` ledger as a real, known gap in shadcn's vendored
destructive variant rather than waived, pending a proper look in a later redesign pass.

## Files touched

148 `.tsx`/`.ts` files across `resources/js` (158 total including `plan/`, `docs/`, and config —
utility migration + icon swap are mostly import-line/class-string edits); `plan/codemods/*.mjs` (3
new scripts + updated README); `resources/js/lib/
iconBundle.ts` + `.test.ts`, `scripts/build-icon-bundle.mjs` (deleted); `resources/js/components/ui/
{Card,Card.test,Toggle,Toggle.test}.tsx` → `{LegacyCard,LegacyCard.test,Switch,Switch.test}.tsx`
(renamed); `resources/js/components/ui/{card,badge,button,toggle,toggle-group}.tsx` + `.test.tsx`
(new, vendored); `resources/js/components/ui/Icon.tsx` + `.test.tsx` (new); `resources/css/app.css`,
`resources/views/app.blade.php`, `resources/brand/grounds.json`, `package.json`,
`package-lock.json`; `docs/features/settings.md`, `.claude/skills/temari/SKILL.md`,
`docs/design-tokens.md` (doc sync).

## Blockers

`F2`. Cleared — `F2` merged as `5e5a3d6f`.

## Acceptance criteria

- [x] Fixed-identity utilities (`--mood-*`, `--rarity-*`, `--color-strava-orange`, `--color-horizon`
      as accent fill, `--color-ink-on-sky`) are byte-identical, untouched by the sweep.
- [x] App builds and the full existing test suite passes after the sweep (wave-1 exit criterion 3).
- [x] `check:chunks` green with the shadcn/base-ui stack in the graph; Login stays under its 160 kB
      gz budget (134.8 kB) (wave-1 exit criterion 4).
- [x] `check:palette` still passes — no raw Tailwind colours, no default shadows (`card.tsx`'s
      `shadow-md` swapped for `shadow-e1`), no off-scale radii (wave-1 exit criterion 5, unmodified
      by this slice but re-verified).
- [x] `@iconify/react` fully removed: dependency, bundle, build script, bootstrap call all gone.
- [x] Every real production icon key (frontend literals + `config/temari_unlocks.php`) has an
      `Icon.tsx` `ICON_MAP` entry — fixed a pre-existing silent-no-icon bug for 8 accessory keys as
      a side effect.
- [x] No case-only filename collisions in `resources/js/components/ui/` (would break TypeScript and
      any case-insensitive filesystem).
- [x] `resources/brand/grounds.json` fully accounts for every `bg-*`/panel the sweep's renamed or
      newly-vendored files paint — no unclassified backgrounds, no unregistered panels, no new
      below-AA pair outside the pinned `belowAa` ledger.
- [x] 1:1 test coverage for every new file (`Icon.tsx`, the 5 vendored primitives).
- [x] Doc citations resolve (`Toggle.tsx` → `Switch.tsx` fixed in `docs/features/settings.md`);
      `design-tokens.md`/`SKILL.md` updated for the renamed classes so they don't prime future work
      with dead vocabulary.

## Coverage delta

Frontend: 95.06% → 95.04% functions, 95.46% statements (unchanged), 95.86% lines (unchanged) —
within the fixture/test churn from the fixture updates in `Design.test.tsx`/`designTokens.test.ts`
(new tokens declared to match `grounds.json`'s expanded classification); all three gates stay
comfortably clear of the 95% floor. Backend: unaffected (no PHP application code touched; only
`tests/Unit/Architecture/*` scanning tests, which pass unchanged against the new file set).

## Verification notes

Full ladder run after every commit, stopping at the first failure and widening once clean:
`./vendor/bin/sail pest --group=structure --no-tia`, `./vendor/bin/sail npx tsc --noEmit`,
`./vendor/bin/sail npm run build && npm run check:chunks`, `./vendor/bin/sail npm run test`
(full, 2022 tests), `node scripts/check-raw-palette.mjs`,
`./vendor/bin/sail php scripts/check-doc-citations.php`, and a full
`./vendor/bin/sail bin pest --parallel --no-tia` (3627 tests) before opening the PR.
`npm run test:coverage` run once at the end to record the delta above — no local coverage driver,
so this is a local sanity check, not the CI-authoritative number (see `reference_no_local_coverage_driver`).

Two genuine "green CI would have missed this" catches worth naming: the `resources/css/app.css`
composite utilities (`.text-stat`, `.voice`, etc.) were still wired to the non-ground-reactive raw
palette after the class-name migration — nothing would have failed, the dark ground would just have
rendered unreadable text. And the icon audit (grepping `config/temari_unlocks.php`, not just
`resources/js`) surfaced 8 accessory icon keys that were never in the old bundle at all — a
pre-existing bug this slice fixed as a side effect of building `Icon.tsx`'s lookup table correctly.

**Browser-review sweep (R2), post-PR.** `npm run build && check:chunks` clean (Login 134.8 kB gz,
still under its 160 kB budget). Full-route screenshot sweep (default viewport matrix, all 13
list-page routes) came back with zero horizontal-overflow findings; a Sonnet inspection pass over
every shot found no broken/missing icons and no Card/Badge/Button contrast or padding regressions.
A one-off pass covered `/activities/{id}` (Runs/Show — missed by the route-sampler because the
`/activities` list route was absorbed into `/history` in an earlier slice, unrelated to `F3`) plus an
explicit light-vs-dark comparison (verified server-side via `document.documentElement.dataset.theme`
before each shot) on the app's most icon/card-dense pages. One apparent finding — a blank chart on
`CtlTrendChart` at desktop width — was chased down and confirmed a **false positive**: the canvas
renders correctly (verified via `getComputedStyle`/cropped screenshot), the full-page capture was
just landing mid-`Chart.js` draw animation (900ms). A second apparent finding — dark ground looking
almost entirely unchanged on Today/Home and page chrome (nav bar, `AppShell`'s root background) —
was chased down to `getComputedStyle` on `<html>`: the semantic layer's CSS variables (`--color-background`,
`--color-card`, `--color-foreground`) **do** correctly flip under `[data-theme="dark"]` (`base` layer
outranks `theme` layer in the cascade, confirmed against the compiled stylesheet). What doesn't flip
is `AppShell.tsx`'s root wrapper (`bg-cream-deep`, a fixed raw-palette literal) and most of
`Home.tsx`'s dashboard markup, both of which predate `F2` and were never in `F3`'s scope — `AppShell`
is explicitly `F4`'s rebuild target ("wire the appearance toggle") and the dashboard is `S3` (wave
2b), blocked on `F4`/`F5`/`B2`-`B4`, not started. Confirmed this is expected pre-`F4` state, not an
`F3` regression, and left untouched rather than scope-creeping into `F4`'s job. **Takeaway for later
waves:** a full "both grounds" visual sweep only makes sense once `F4` lands (the shell + toggle);
doing it any earlier will always show most of the app looking unthemed, correctly.

## Open questions

- **`Toggle`/`SectionTabs` vs `toggle`/`toggle-group`** (see Deviations). Both new primitives stay
  vendored for when a real segmented-control or press-toggle need shows up; revisit whether either
  legacy component should eventually be redrawn on top of Base UI's own `switch` primitive
  (`@base-ui/react/switch`, already installed, unused) rather than the current hand-rolled
  `role="switch"` button, if a future slice wants to align it visually with the new stack. Not this
  slice's job — no visual parity promised, per decision 5.
- **`Chip`/`PillLink`, and the 16 untouched `PillButton` call sites** stay entirely on the legacy
  tone-based components. A screen-redesign slice that wants to fully adopt `Badge`/`Button`
  everywhere will need real visual judgment calls (does `Chip`'s soft-tint treatment survive, or
  does the whole tone vocabulary get redrawn onto solid fills) — explicitly deferred, matching
  decision 5.
None outstanding for `cn.ts` — its `font-size` group comment names `text-text-2` as the example
color (pass 1's plain-text rewrite updated the comment along with the code, since it's just a
string match), so there's nothing stale there to track.
