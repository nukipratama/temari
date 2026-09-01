# PS2 — Onboarding

**Program** prototype parity · **Slot** 1 (worktree) · **Blockers** `PP0`-`PP3`, `PS11` ·
**Status** in-review

The last of the eleven screen slices.

## Goal

Bring `/onboarding` to the prototype's section list, order and treatment at P2 fidelity, against
[OnboardingScreen.tsx](../../../resources/brand/prototype/src/components/pages/OnboardingScreen.tsx)
and its
[PreferenceControls.tsx](../../../resources/brand/prototype/src/components/pages/PreferenceControls.tsx),
with [reference.md](../reference.md) §4 as cross-check.

Onboarding is chrome-free (P34, `bareLayout`), carries `FaceIcon` at two placements (P10), and is
the only **520px** column in the program with a `pt-16` that does **not** shrink at 900px (P5).

## What was verified rather than redone

Four of the brief's five claims were already true in the tree, and are recorded here so the next
reader does not re-open them.

| claim | verdict |
|---|---|
| **P34** — Onboarding sits on `BareShell` | **already true.** `Index.tsx:713` is `OnboardingIndex.layout = bareLayout`; `PP1` moved it. Not redone. Onboarding is **not** in `check-entry-chunks.mjs`'s `ROUTE_BUDGETS_KB`, so the framer-motion ban that shapes `PS1` does not apply here — the page and all four shared controls already import it. |
| **P5** — 520px column, `pt-16` does not shrink | **confirmed at source.** `OnboardingScreen.tsx:685` is `px-4 pt-16 pb-10 @min-[900px]:mx-auto @min-[900px]:max-w-[520px] @min-[900px]:px-6 @min-[900px]:pt-16 @min-[900px]:pb-16`. Both halves of `reference.md` §1.1's Onboarding row are accurate. **One half was unimplemented**: the app carried `PageContainer`'s `py-6`, so bottom padding was 24px at both widths instead of 40 → 64. Fixed. |
| **`FaceIcon` at 72 and 26** | **confirmed** at `OnboardingScreen.tsx:269` and `:584`. Both were already present at the right sizes. |
| **P36** — card radius `rounded-md` (14px) | **was not applied here.** Onboarding was the last screen still mounting shadcn `Card` (`rounded-4xl`, 26px) — twice — plus three hand-rolled `rounded-xl` (24px) surfaces where the prototype draws `rounded-[14px]`/`rounded-[12px]`. This slice is where P36 lands on Onboarding. |
| **P3** — controls the prototype wires to nothing | **already satisfied**, as on `PS5`. `reference.md` §4 lists three dead controls: the name input (`:605-609`, no `value`/`onChange`), the race-day input (`:613-616`, same), and "set my goal & finish" / "skip for now" both calling the identical `onFinish` (`:659-671`). All three were live before this slice — both fields are controlled state posted to `POST /onboarding`, and the two buttons post different payloads (`submitGoal` vs `skip`). Nothing to implement; the fourth preceding slice to find P3 pre-paid. |

## Reconciling with `PS11`'s shared controls

The prototype shares `IconChoiceCard`, `SessionsDial` and `DayCell`/`DayRow` between Settings and
Onboarding (one `PreferenceControls.tsx`, imported by both screens). `PS11` built Settings on the
app's existing `components/onboarding/{IconChoiceCard,SessionsDial,DayPicker}.tsx` rather than
forking them. This slice keeps that: **the three shared controls are not rebuilt.**

One shared-control change was made, and only because P36 forces it: `IconChoiceCard`'s container
was `rounded-xl` (**24px**) where the prototype draws `rounded-[14px]`
([PreferenceControls.tsx:131](../../../resources/brand/prototype/src/components/pages/PreferenceControls.tsx)).
That is a 10px miss on the exact value P36 settles, in a control that renders on both screens, so
it moves to `rounded-md`. Settings picks the change up for free and gets *closer* to its own
prototype, not further.

Two shared-control divergences were **left alone** deliberately:

- The active tint is `bg-horizon/10` in the app, `bg-horizon/20` in the prototype
  (`IconChoiceCard`, `DayCell`, and the prototype's local `PillOption`). Changing it needs a new
  `horizon/0.2` registration in `grounds.json` and shifts Settings' rendering. A tint step is not a
  P2 sizing value, `PS11` shipped `/10`, and the app's own `outlineChipVariants` uses `/10` too —
  so `/10` is the house value and three screens agree on it.
- `DayCell`'s flag glyph is `mdi:flag-checkered` where the prototype uses lucide `Flag`. Icon-set
  vocabulary, not layout.

## What changed

### Root container (§1.1)

`pt-16 pb-10 min-[900px]:max-w-[520px] min-[900px]:pb-16` on `PageContainer`. `PageContainer`'s own
`px-4 min-[900px]:px-6` and the `pt-16` already matched; `max-w-[520px]` overrides its 760 through
`cn`'s tailwind-merge. The bottom step is new.

### 2 — `ConnectedStep`

- The **radial horizon glow** behind the 72px `FaceIcon` (`:260-275`) was missing entirely. Added
  on `PS1`'s idiom (`var(--color-horizon)` in a `color-mix` radial gradient, inline style,
  `pointer-events-none aria-hidden`), so Login and Onboarding draw their glows the same way.
- The headline breaks to two lines with the name in the accent (`:276-280`):
  "you're connected," / *`{firstName}.`* in `text-icon-accent`.
- The "what lands" card moves from shadcn `Card` (`rounded-4xl`, `--card-spacing` 24px) to
  `LegacyCard` (`cardVariants`: `rounded-md` + `pad-card`), matching the prototype's
  `rounded-[14px] p-4` exactly under P36/P2.
- "continue" becomes a full-width `PillButton tone="horizon"` — the prototype's full-width
  `rounded-full` primary — instead of the `h-9` auto-width shadcn `Button`.

### 3 — `PreferencesStep`

- Question headings move from `text-headline-xs` (18px on mobile) to `text-quote-lg` (**22px
  fixed**), which is the prototype's `text-[22px]` exactly. The quote tier is the right register
  anyway: these are fixed-px because reading distance is constant.
- The option list gains the prototype's `staggerContainer` + per-item `fadeInUp` (`:472-493`), and
  `SessionsDial` its own `fadeInUp` (`:460-470`).
- The days sub-step's nested long-run card goes `rounded-xl` (24px) → `rounded-md`; the prototype
  draws `rounded-[12px]`, and 14 is the nearest step on the scale (10 and 14 straddle it; 14 is the
  card corner P36 settles).
- Its inner heading goes `text-sm` → `text-quote-sm` (15px vs the prototype's 16px).

### 4 — `GoalStep`

- The **recap line** (`:532-536`) was missing. It now renders "Got it: {summary}." in the serif
  italic voice register whenever at least one preference was answered, built from the same three
  answers the prototype joins with `·`. Written with a colon rather than the prototype's em-dash,
  per the repo's em-dash preference.
- The "optional" label moves from a `PageHero` eyebrow **above** the headline to an inline `Chip`
  **beside** it (`:537-546`), and the headline breaks with "in mind?" in the accent.
- The pace card gains its own glow blob (`:554-560`), drops `rounded-xl` → `rounded-md`, and the
  pace readout goes from `text-headline-xs` (18px on mobile) to the 32px stat register — the
  prototype's `text-[32px]`.
- The form card becomes `LegacyCard`, and its fields adopt the primitives `PS5` used for the *same
  prototype form* on Race: `inputVariants()` for name/date, `inputVariants({size:'sm'})` for the
  hour/minute pair, `outlineChipVariants({selected})` for the distance presets, and
  `text-label-micro text-text-2` field labels. Race and Onboarding now render one form treatment.
- The action row moves **outside** the card (`:658-672`), both buttons `flex-1`: a horizon
  `PillButton` submit and a ghost `PillButton` skip.

## Files touched

| file | what |
|---|---|
| `resources/js/pages/Onboarding/Index.tsx` | the rebuild |
| `resources/js/pages/Onboarding/Index.test.tsx` | headline/copy assertions, glow + recap coverage |
| `resources/js/components/onboarding/IconChoiceCard.tsx` | `rounded-xl` → `rounded-md` (P36) |
| `resources/brand/grounds.json` | surgical: `Index.tsx` no longer paints `bg-horizon/10` directly |
| `plan/parity/README.md` | §5 `PS2` row |

## Blockers

None. `PP1` shipped the shell and the layout, `PP2` the `FaceIcon` placements, `PP3` the cuts, and
`PS11` the shared preference controls. This slice is content-only.

## Acceptance criteria

- [x] Root container is the prototype's own: 520px cap, `px-4` → `px-6`, `pt-16` at **both**
      widths, `pb-10` → `pb-16`.
- [x] Section list and order match §4: `StepProgress` → `ConnectedStep` / `PreferencesStep`
      (+ days sub-step) / `GoalStep`.
- [x] `FaceIcon` at 72 (connected) and 26 (pace ring), and nowhere else.
- [x] Both radial glows drawn.
- [x] No shadcn `Card` and no `rounded-xl` card surface left on the screen — every card is
      `rounded-md` (P36).
- [x] The recap line renders only when a preference was answered.
- [x] The goal form's name, race day, distance and goal time all post real values; skip posts a
      distinct payload (P3, already true, re-asserted by test).
- [x] `composer check` green; `npm run build` + `check:chunks` green.
- [x] `DesignTokenContrastTest` green with `grounds.json` regenerated.

## Coverage delta

Measured on this worktree against `epic/mobile-ux-port` @ `e69a84c7` itself (not a sibling's
baseline), `npm run test:coverage`, full suite both sides, each run uncontended:

| | before | after |
|---|---|---|
| statements | 97.63% | **97.64%** |
| branches | 91.48% | **91.49%** |
| functions | 97.32% | **97.33%** |
| lines | 97.84% | **97.85%** |

Up a hair on all four, 1827 → 1829 tests. Both runs are on this worktree with the same
configuration, the "before" from a detached checkout of `e69a84c7` itself rather than a sibling's
recorded baseline, and both taken **before** the epic merge so the delta is this slice's alone.
The screen is a like-for-like rewrite in one page component: `preferencesSummary` and `ChoiceList`
are the only new functions, both covered.

## Verification notes

**Gate**: `./vendor/bin/sail composer check` green **end to end in a single run, exit 0** — pint ·
phpstan (0 errors) · rector (0 errors) · pest `--parallel --no-tia` 3659 tests / 10855 assertions ·
tsc · eslint · prettier · vitest 1834/1834 across 219 files · `check:palette` (460 files, zero
off-token) · `check:chunks` · the doc-citation and `{@see}` guards. Run uncontended, with both
sibling stacks down. `npm run build` + `check:chunks` green separately: no route is over budget and
**no entry-chunk budget was re-baselined** (Login 140.6 / Home 201.0 / Runs/Show 205.9 / Profile
186.0 kB gz). Onboarding is not itself a budgeted route.

**`grounds.json`** needed one surgical deletion, caught by `DesignTokenContrastTest`'s
"registered panel call sites paint nothing any more" arm: moving the distance presets onto
`outlineChipVariants` meant `Index.tsx` stopped painting `bg-horizon/10` itself, orphaning its
`horizon/0.1` registration. One block removed, nothing re-sorted; the file is otherwise
byte-identical. `IconChoiceCard.tsx` and `DayPicker.tsx` still paint it and keep their rows. Both
grounds are covered the way this repo covers them — the test scores every registered pair against
each ground and fails closed. Green, 39/39 in the `structure` group.

**Not done: a real browser pass.** Like `PS11`, the 900px step and the two grounds are verified at
the class-string and token-contrast level only. Worth one sweep before the epic merges — and the
two glows this slice adds are the kind of thing only a render shows.

## Open questions

1. **The skip affordances are an app addition the prototype has no equivalent for, and they
   stay.** `PreferencesStep` in the prototype has exactly one control besides the answers: the back
   chevron (`:432-443`). There is no way to decline a question. The app draws a "Skip for now" pill
   in the step header and a "Skip this" link under each question, and `POST /onboarding` accepts a
   partial (or empty) payload — `OnboardingController::store` guards both writes with `filled` /
   `hasAny`. Removing them would make four answers mandatory to reach the goal step. The prototype
   never persists an answer, so a wrong pick there costs nothing; here every answer drives plan
   generation, and "no answer" is materially different from "a guess". Kept, in the same class as
   `PS11`'s three deliberate non-copies: copying the prototype would remove something true.
2. **The pace readout is mono, not serif.** The prototype draws it `font-serif text-[32px]
   font-bold` (`:596`). It ships as the app's `text-stat` register — same 32px, but mono, bold and
   tabular — because the design system reserves serif for headlines and Temari's voice and puts
   numbers on JetBrains Mono. Size and accent colour match; the family does not, on purpose.
3. **Per-screen bottom padding now *is* implemented here**, unlike `PS8`/`PS11`, where `AppShell`
   supplies a `pb-7` that a page-level step would stack on top of rather than replace. Onboarding
   is on `BareShell`, which supplies none, so `pb-10 min-[900px]:pb-16` on the page's own container
   is the whole story. `PP1`'s open question 2 stays open for the nine chromed screens.

## Notes for the wrap-up slices

- **`W2` (dead code): no new orphans.** Dropping shadcn `Card` and `Button` from this page leaves
  both with other consumers (`Card`: `GoalCard`, `DataTable`, `DailyChart`, `Login`, `InlineNote`;
  `Button`: `PushNotificationToggle`, `AskAboutRun`, `Login`). `PageHero`'s `eyebrow` prop likewise
  survives on Inbox, Settings and `HistoryHeader`.
- **`W1` (routes/IA): nothing to do.** `/onboarding` keeps both its routes and its gate;
  `EnsureOnboarded` and `OnboardingController::show`'s redirect are untouched.
- **`PP4` (demo seed): Onboarding is deliberately unreachable for the demo account** and P30 cannot
  cover it. `DemoRunSeeder` heals the demo user to onboarded on every re-seed
  (`DemoRunSeeder.php:645-650`), and `OnboardingController::show` redirects an onboarded user to
  the dashboard. This is correct — a showcase account should not land in a wizard — but it does
  mean the last screen of the eleven has **no demo-visible surface**, so a browser sweep must sign
  in as a fresh non-onboarded user to see it at all. Worth stating in `PP4` rather than being
  discovered as a missing screenshot.
- **`PS12` (cross-screen treatment): one new entry**, the active tint on the shared preference
  controls. Recorded in `README.md` §5 rather than settled here, since it spans Onboarding and
  Settings.
- **Every `@min-[900px]:` utility Onboarding carries is now implemented.** Its root wrapper is the
  screen's only container query (`reference.md` §4, "At `@min-[900px]:`", verified: none in
  `PreferenceControls.tsx`), and it takes no row in `reference.md` §1.2's reflow table. With this
  slice, `PP1`'s eleven-row table is fully accounted for across the eleven screens.

## Plan/prototype discrepancies found

None in `reference.md` §4 — it is accurate throughout, including the two claims the amendments log
had already corrected once (520px, and `pt-16` not shrinking), the two `FaceIcon` placements, and
all three "dead" controls. Another §-level cross-check comes back clean, after `PS5`'s §7 and `PS7`'s §9.

One **unimplemented** plan claim rather than a wrong one: `reference.md` §1.1 gives Onboarding
`pb-10` → `pb-16`, and the shipped page carried `PageContainer`'s `py-6` at both widths. Fixed
here. This is the same class of miss `PS1` found in reflow #5 — a row read at one half only.

And one **stale P36 application**: Onboarding was the last screen still mounting shadcn `Card`
(`rounded-4xl`, 26px), which P36 names explicitly as the thing card radius is *not*. `PP3` and
`PP1` both passed over this page without touching its card surfaces, so the decision ratified by
merging #688 had never reached it.
