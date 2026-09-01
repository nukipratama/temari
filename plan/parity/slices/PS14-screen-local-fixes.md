# PS14 — Screen-local parity fixes

The divergences the 2026-09-01 browser sweep confirmed that are **local to one screen**, so none
of them belongs in `PS12`. Plus **P38** (History's `RecapCard`), plus the `goal_sec` chip
reassigned out of `PP4`.

Runs after `PS13` and alone. Every item is small; what makes them a slice rather than a sweep is
that four of the eight needed their recorded description corrected before they could be
implemented, and two needed a user ruling.

## Goal

Each item below either matches the prototype or is recorded here as a deliberate divergence.

## The items

| # | screen | recorded claim | verified | what ships |
|---|---|---|---|---|
| 1 | Login | hero wordmark renders `Temari` | **already fixed** | landed in `PS13`; no work here |
| 2 | Login | kartu thumbnail 130x98 vs `h-21 w-19.5` = 78x84 | **wrong** | see below |
| 3 | Inbox | "open" link drops the trailing `ArrowRight` | confirmed | add it to `InboxRow`'s `PillLink` |
| 4 | Plan | header AI-replan trigger is a ghost pill | confirmed | filled `bg-muted` mono uppercase pill |
| 5 | Today | plan-card phase badge lost its `uppercase` | **true, wrong cause** | see below |
| 6 | History | `BellPlus` is a full-width pill | **understated** | see below |
| 7 | Settings | push row uses a message glyph | confirmed | `mdi:cellphone-message` → `mdi:cellphone` |
| 8 | History | P38: `RecapCard`'s six metrics on the prototype's chip shape | **premise wrong** | see below |
| 9 | Profile | `goal_sec` hardcoded `null` (from `PP4`) | confirmed | wire to the active `RaceGoal` |

### 2 — Login's kartu teaser is not a sizing bug

The prototype draws a **decorative 78x84 tile**: a sky gradient, a white route squiggle, a
`Legendary` tag (`LoginScreen.tsx:166-183`). The app draws the **real `KartuMini`**, hard-fixed at
`w-[140px]` (`KartuMini.tsx:66`) with an intrinsic height around 150px — art window, route glyph,
Temari mark, rarity symbol, name, date, edition. The teaser card measures 183px tall live against
the prototype's ~112px. The recorded "130x98" appears in neither source and was a mis-measure.

**Ruled by the user**: `KartuMini` gains a compact mode — art window and rarity tag only, no stat
block — sized to the prototype's 78x84 slot. Same precedent as **P16**: the prototype drew a
stand-in because it had no card renderer, so the real renderer goes into the slot the prototype
sized. The alternative of drawing the prototype's tile literally was rejected because Login is the
one screen where a logged-out visitor judges the feature, and the teaser's own copy sells "a real
card, not a mockup".

### 5 — no `Chip` in this app has ever rendered as a label

The badge is genuinely not uppercase, but not because a class was dropped at the call site.
`chipVariants` (`lib/variants.ts:94-115`) puts `text-label-micro` in its base and `text-[11px]` in
its `sm` size variant, which is the default. `cn.ts` registers `text-label-micro` in
tailwind-merge's **`font-size`** group — deliberately, so it can coexist with a text colour — so
the size variant and the base collide and the later one wins. `text-label-micro` is stripped from
**every `Chip` in the app, always**.

Verified live rather than read: the Today phase badge computes `text-transform: none` and
`font-family: "Plus Jakarta Sans"`, and its rendered class list contains `text-[11px]` with no
`text-label-micro`. That is the mono, the uppercase, the `font-bold` and the `tracking-[0.12em]`
all silently gone.

The fix is deliberately **not** "make `Chip` uppercase again". The prototype uses both treatments:
its Today phase badge and History recap chips are mono uppercase, its Profile journey chips
(`ProfileScreen.tsx:514-520`) are sans, sentence-case `font-bold`. Restoring the base would repaint
every chip in the app to match only half of them. So:

- `chipVariants` loses the `text-label-micro` that has never applied. **Zero rendered change** — it
  is stripped today at every call site — but the component stops claiming a style it does not have.
- `WeekPlanWidget`'s phase badge passes `text-label-micro` itself, where `className` is merged last
  and therefore wins.

### 6 — History has no bell

The recorded claim reads as a shape difference. It is a different affordance: there is no
`BellPlus` anywhere in `resources/js`, and no bell icon. The app renders `SendNotificationButton`
(`mdi:send`, a labelled full-width `PillButton` with a cooldown timer in its label) on its own row
below the chips, gated on `analysis.status === 'done'` and on notifications being reachable. The
prototype draws a `size-6` circular `bg-muted text-icon-accent` icon button **inline in the chips
row**, under a `justify-between`.

Ships as the prototype's shape, with the states P1 keeps folded into it rather than dropped:
sending and cooling swap the glyph and disable the button, and the cooldown's remaining time moves
into the `title` and the accessible label. The gating is unchanged, including the part that reads
as an exception: a recap whose narration is not `done` draws no button, but a user with **no channel
wired** still gets it, muted, opening the "turn notifications on" nudge. That is deliberate and
predates this slice — the control is a discovery surface there, not a false affordance.

### 8 — P38's premise does not survive verification

P38 rules that `RecapCard`'s six metrics survive, "redrawn in the prototype's compact chip
treatment". But `WeeklyStatusChips`' `CHIP_BASE` is already **byte-identical** to the prototype's
chip class string. The redraw is a no-op; the real divergences are colour and layout.

**Ruled by the user**: Fitness (`bg-leaf/15`), Readiness (`bg-horizon/15`) and the per-state
form-status tint drop to the prototype's uniform `bg-muted text-foreground`. Monotony and Drift
**keep** their alert tint, which only fires past a real threshold — a chip that is never coloured
except on a genuine alert is a signal rather than decoration, and it is the reason the six-metric
strip exists. `MetricExplainer` stays on the two chips that carry it, being P1 plumbing rather than
ornament. The chips row becomes `justify-between` with item 6's button.

P38 is amended in `README.md` §2 in the same commit, since its stated rationale is now wrong.

### 9 — the goal chip, and the fixture gap under it

`ProfileController.php:149` passed `fn (PersonalRecord $pr): ?int => null` to
`ProgressionSeriesBuilder::buildMany()`, so `ProgressionCard.tsx:95`'s chip rendered for no user on
any data. **Ruled by the user**: resolve from the user's active `RaceGoal` (`distance_m` +
`goal_time_sec`), matched to the one progression category whose distance falls inside the builder's
own +/-5% band. No active race, or a race at a distance with no PR category, means no chip. The
alternatives were checked and rejected: `season_goals` is a generic `title`/`metric`/`target`/`unit`
row — the five-goal-tier module **P24** cut — and cannot express a per-distance time target.

**And a fixture gap in the same shape as `PP4`'s**: `demo:seed` creates **no** `RaceGoal`. It only
reads one (`DemoRunSeeder.php:467`), and `ensureDemoUser`'s own comment — written by `PP4` —
claims the training preference matches "the race goal seeded alongside". There is no such row. The
only reason the demo account has a race today is that one was hand-created in the dev database
while testing. On a fresh `migrate:fresh` + `demo:seed`, Race renders its empty state and this new
chip renders nowhere, which is exactly the failure `PP4` existed to close. One `RaceGoal` is
seeded, `updateOrCreate` so re-seeds converge, and the stale comment is corrected.

## Out of scope

| excluded | reason |
|---|---|
| The `AnalysisStatus` regenerate control | `PS12` owns it. Item 4 is a **different** trigger on a different surface: Plan's own header button, which calls `regenerate` directly. |
| `Chip`'s `bg-horizon/[0.18]` vs the prototype's `bg-horizon/20` | The active-tint call is `PS12`'s, and it spans two screens. |
| Repainting every `Chip` to mono uppercase | The prototype uses both treatments; see item 5. |
| Plan's `mdi:sync` vs the prototype's lucide `RefreshCw` | The app is on Iconify; the glyph is equivalent. Item 4 is the pill's fill, case and type, not its icon set. |

## Blockers

None. `PS13` is merged.

## Acceptance criteria

1. Each of items 2-9 matches the prototype or is recorded above as a deliberate divergence.
2. `demo:seed` on a **fresh** database produces a user for whom Race and the goal chip both render.
   Verified against `migrate:fresh`, not an already-populated database — `PP4`'s lesson.
3. P38's entry in `README.md` §2 states the corrected premise.
4. `./vendor/bin/sail composer check` green (`--no-tia` on pest, see below).
5. A browser pass over Login, Today, Plan, History, Inbox, Settings and Profile confirms all eight
   live, including the phase badge computing `text-transform: uppercase`.

## Coverage delta

Record before/after. Expected roughly flat: one new controller branch, one new seeder row, one new
`KartuMini` mode, each with its own test.

## Verification notes

- **The phase badge was measured, not read.** A source read concluded the `Chip` *did* uppercase,
  because `chipVariants` names `text-label-micro` in its base. One `getComputedStyle` disagreed.
  Any claim about a class that passes through `cn` needs the computed value, not the source.
- Four of the eight recorded items were wrong or understated. Verify against prototype **source**
  before implementing, including claims written in this program's own docs.
- `composer check` cannot complete locally: pest fails on `The feature "Tia mode" requires "git"`,
  pre-existing and reproducing on an unmodified `--group=structure`. Verify with
  `./vendor/bin/sail bin pest --parallel --no-tia`.

## Open questions

1. The compact `KartuMini` is a second mode on a component with one other call site. If a third
   mode is ever wanted, it is probably two components rather than three modes — noted, not acted on.
2. `chipVariants`' `md` size still emits a bare `text-[12px]`, so it would strip a caller's
   `text-label-*` the same way. No current caller passes one. Left alone rather than pre-solved.
