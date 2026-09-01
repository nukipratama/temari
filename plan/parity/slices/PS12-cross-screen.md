# PS12 — Cross-screen treatment reconciliation

The three treatment calls that span already-ported screens and could not be settled inside any one
of them. Deferred by the user on 2026-09-01 until all eleven screens had landed, deliberately: the
question in each case was whether the **prototype** is consistent, and only a finished port could
answer that.

It could not be answered from `reference.md` either. All three items were verified against
prototype source before implementing, and **two of the three had their premise change**.

## Goal

Each of the three is settled the way the prototype actually draws it, applied once at the shared
component rather than per screen.

## 1. The regenerate control

Raised by `PS6`. The prototype draws a bottom-right pill; `AnalysisStatus` draws a left-aligned text
trigger, and seven components render it — `TodaySession`, `TemariTake` (Plan), `NarrationCard`
(Trends), `RecapCard` and `CalendarWeekRow` (History), `RunLenses` (Activity) and `ProfileHero`.

**The prototype has two different controls under this one name, and only one of them is this one.**

- **Narration reread** — `TrendsScreen.tsx:146-181` and `ActivityDetailScreen.tsx:583-666`. These
  two are **byte-identical** in class and placement: a `bg-muted` mono uppercase pill carrying
  `RefreshCw`, in a `flex justify-end` row at the **bottom right inside the narration card**, and a
  cooldown that swaps the button for a `<span>` with `Clock` reading "next read in 4h 12m". This is
  `AnalysisStatus`.
- **Whole-object regeneration** — Plan's header, Plan's empty state, Race's form, Settings'
  preferences. Same pill styling, but each screen hand-rolls its own ready state and only the
  cooldown is shared (`AiReplanPill.tsx`). **Not this control**; Plan's header instance already
  landed in `PS14`.

So the prototype **is** consistent about this control, across the only two screens that draw it. It
ports as written:

- the trigger becomes the filled `bg-muted` mono uppercase pill, right-aligned (`self-end` inside
  the existing flex column, which is `justify-end` by another name),
- `mdi:auto-awesome` becomes `mdi:sync` (the app's alias for lucide `RefreshCw`), and the cooldown
  state takes `mdi:clock-outline`,
- the cooldown label becomes `next in {h:mm:ss}` rather than a bare duration, matching the wording
  `PS14` shipped on Plan's header.

**The failed state's "try again" comes along.** It is the same control on the same surface, one row
lower, and leaving it a left-aligned text link beside a right-aligned pill would make the component
disagree with itself. It takes the same pill, keeping its distinct label. The prototype draws no
failed-narration state at all — this is P1 plumbing being styled to fit, and it is recorded here
rather than left to look like parity.

## 2. The empty-state shape

Raised by `PS7`, sharpened by `PS5` and `PS9`. The recorded state of it: the *shape* is per-screen
(History and Inbox horizontal, Race centred, two-to-one), and what remained was "one call" — the
prototype's 40px face against `EmptyPanel`'s 48.

**That last constant does not exist either.** The prototype draws **both** sizes:

| prototype | line | face | layout |
|---|---|---|---|
| `InboxScreen` `EmptyInboxCard` | `261-281` | **40** | horizontal |
| `HistoryScreen` `NoRunsCard` | `509-529` | **40** | horizontal |
| `RaceGoalScreen` `NoRaceState` | `226-243` | **40** | centred |
| `PlanScreen` `NoPlanState` | `1628-1652` | **48** | centred |

So both axes are per-screen, and Plan — the one screen at 48 — is already a shipped `EmptyPanel`
call site drawing 48. The shipped component hardcodes `size={48}` and `text-center` with no
alternative, so it is right on exactly one of its four face-bearing call sites by accident.

`EmptyPanel` gains both axes and each call site takes the prototype's own values:

| call site | face | layout |
|---|---|---|
| `Plan.tsx` | 48 | centred |
| `Race.tsx` | 40 | centred |
| `Inbox.tsx` | 40 | horizontal |
| `Activities/Feed.tsx` (History) | 40 | horizontal |

**40 becomes the default**, per the consolidation rule: pick the majority value and let the
differing site state itself. The one consequence is `NoVerdictPanel` (Today), which the prototype
does not draw and which therefore has no value of its own to port — it shifts from 48 to 40 with
the majority. The five faceless call sites (chart "not enough history" placeholders) pass no `face`
and are untouched.

## 3. The active tint

Raised by `PS2`, which left `bg-horizon/10` standing rather than change a merged screen. The
prototype paints `bg-horizon/20`.

**One of the three named controls does not have this tint at all.** `SessionsDial` fills solid
(`bg-icon-accent`) in both the prototype (`PreferenceControls.tsx:189-192`) and the app
(`SessionsDial.tsx:36`) — it already matches and is not touched.

The prototype's `/20` is its selected-control tint specifically: `PreferenceControls.tsx:67` and
`:133`, `RaceGoalScreen.tsx:59`, `OnboardingScreen.tsx:248`. Its `/10` and `/14` appear on
**decorative** surfaces instead, and one of those matters: `ActivityDetailScreen.tsx:985` is `/10`,
and the shipped `LapsCarousel.tsx:35` already draws `/10` there. **That call site is correct today
and stays** — a blanket `/10` → `/20` sweep would have broken it.

What moves to `/20`:

- `IconChoiceCard.tsx:41` and `DayPicker.tsx:68` — the two controls the prototype draws at `/20`.
- `outlineChipVariants`' selected state (`variants.ts:179`), whose four call sites are Race's and
  Onboarding's distance presets (the prototype's `RaceGoalScreen:59` and `OnboardingScreen:248`,
  both `/20`), Profile's progression tabs and Activity's question chips.

`DayPicker`'s long-run cell already carries `ring-horizon/20` identical to the prototype, and stays.

**The token pairing is deliberately left alone.** The prototype writes
`border-icon-accent bg-horizon/20 text-icon-accent`; `outlineChipVariants` writes
`border-horizon bg-horizon/10 text-horizon-ink`. Only the alpha is the recorded item; `-ink` versus
`icon-accent` is a ground-reactive semantics question that has never been raised, and folding it in
here would smuggle a second decision into a one-value change.

`grounds.json` needs a new `horizon/0.2` registration and the moved call sites removed from
`horizon/0.1` — surgical edits, never a re-sort.

## Files touched

`components/temari/AnalysisStatus.tsx`, `components/ui/EmptyPanel.tsx` and its four face-bearing
call sites, `components/onboarding/{IconChoiceCard,DayPicker}.tsx`, `lib/variants.ts`,
`resources/brand/grounds.json`, plus the co-located tests of each.

## Blockers

None. `PS14` is merged, and it settled the two items that would otherwise have collided here: Plan's
header trigger (a different control) and History's send button (which shares the recap card's row).

## Acceptance criteria

1. The three items match the prototype, or are recorded above as a deliberate divergence.
2. `LapsCarousel` still paints `/10`, and `SessionsDial` still fills solid.
3. `grounds.json` carries `horizon/0.2` with its real call sites, and `horizon/0.1` no longer claims
   the ones that moved. `DesignTokenContrastTest` green, which fails closed both ways.
4. `./vendor/bin/sail composer check` green (`--no-tia` on pest, see below).
5. A browser pass confirms the pill is right-aligned inside its card on Trends and Activity, that
   the four empty states carry their prototype's own face size and layout, and that nothing
   regressed at 320px — a right-aligned pill in a narrow card is exactly where an overflow would
   appear.

## Coverage delta

Record before/after. Expected flat: new props on `EmptyPanel`, no new logic.

## Verification notes

- Two of three premises changed on contact with source. `reference.md` and this program's own rows
  are a starting point for a search, never the finding.
- **A blanket sweep was the obvious wrong move on item 3** and would have broken a call site that
  is already correct. The alpha is not the unit of meaning; the control is.

## Open questions

1. The prototype labels the same control "regenerate" on Trends and "reread" on Activity. The app
   says "reread" everywhere. Left as-is: one word per control beats one word per screen, and P37
   does not reach word choice.
2. `AiReplanPill`'s family — Plan's header (shipped in `PS14`), Race's form and Settings'
   preferences — now share a treatment with no shared component, in the app as in the prototype.
   Worth extracting if a fourth appears; not worth it for three.
