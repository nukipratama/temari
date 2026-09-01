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
| statements | _pending_ | _pending_ |
| branches | _pending_ | _pending_ |
| functions | _pending_ | _pending_ |
| lines | _pending_ | _pending_ |

## Verification notes

_pending_

## Open questions

_pending_

## Notes for the wrap-up slices

_pending_
