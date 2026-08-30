# S8 — ActivityDetail

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `F5`, `F6`, `L0` · **Status** merged ([#673](https://github.com/nukipratama/temari/pull/673), squashed as `0d0c5576`)

## Goal

Port `pages/Runs/Show.tsx` (565 L) + `components/run/` (11 files) — the densest screen in the app.
Depends on `L0` rulings for Leaflet route maps, run lenses and relative effort, none of which the
prototype draws.

## What actually landed

**All three of `L0`'s named dependencies ruled `keep, mechanical only`, and all three were already
there.** Reading the ledger directly (not the stub's paraphrase) confirms: Leaflet route maps
(`RouteMap.tsx`/`MapWeatherPanel.tsx`), run lenses (`RunLenses.tsx`), and relative effort (the
`effortSub` line on the TRIMP tile) all carry the same verdict — re-skin via the token sweep, no
redesign. Running `npm run check:palette` against those three files plus every file this slice
touches came back clean (469 files, zero off-token utilities): `F3`'s mechanical sweep had already
converged all three onto the app's semantic token vocabulary (`border-border`, `rounded-lg`,
`text-text-3`, `Card`/`Eyebrow`/`Icon`/`Chip`) before this slice started. No changes were made to
`RouteMap.tsx`, `MapWeatherPanel.tsx`, or `RunLenses.tsx` — confirmed already-mechanical, not
skipped by assumption.

**`LapsGraph.tsx` and `SplitsTable.tsx` are F6-owned charts, consumed as-is — same posture `S5`/`S6`
took.** `git show ee4f7881 --stat` confirms `F6` ("charts, two grounds") touched both files directly,
fixing a ground-reactivity bug in the shared `barRowFill`/`computeBarWidth` helpers (`lib/splits.ts`)
that both components share. Both already render through the app's converged card/label vocabulary
(`Card as="section" padding="hero"`, `SectionLabel`, `font-mono` uppercase labels) identical to what
the prototype's own `SplitsChartCard`/`LapsCarousel` mockups draw semantically, just via a different
concrete component. Left untouched.

**`EmptyRunsState.tsx` and `RunListRow.tsx` are out of this slice's real design surface, left
untouched.** Checking every consumer of `components/run/` found `EmptyRunsState` is Home's
(`S3`, merged) Strava-empty-state and `RunListRow` is Activities/Feed's and History's
(`S7`, in progress in a sibling worktree) feed row — neither is drawn anywhere in the prototype's
`ActivityDetailScreen.tsx` mockup, and touching `RunListRow` risked a real merge collision with the
sibling `S7` worktree currently active on the same file. The "11 files" count in the stub includes
both; only 9 are actually part of the activity-detail screen itself.

**Hero stat row restructured into the prototype's headline/supporting/secondary tier**, replacing
six equally-weighted `StatTile`s in one flat grid. Reading `ActivityDetailScreen.tsx` found a real
three-tier hierarchy (`HEADLINE_STAT`/`SUPPORTING_STATS`/`SECONDARY_STATS`) the shipped page had
flattened. Rebuilt using only proven, already-shipped `StatTile` variants — `size="lg"` for the
distance headline (already used by `LastRunCard`/`CardReveal` elsewhere, so no untested overflow
risk), `size="md" tone="plainSky"` for duration/pace (the page's own prior hero-tile size), and
`size="sm" tone="sky"` (the existing "glass tile on a sky panel" variant) for HR/TRIMP/elevation as
a compact secondary row. Deliberately did **not** introduce `StatTile`'s unused `size="xl"` variant
for the headline — it has no live call site anywhere in the app and its fixed (non-fluid) 40px value
carries the same narrow-viewport overflow risk `LastRunCard.tsx` already documents for `size="lg"`
in a 3-up row; `size="lg"` alone (not 3-up here) sidesteps that risk entirely.

**`DetailTiles.tsx` gained the `Card`+`SectionLabel("Vitals")` wrapper every other "breakdown" card
(`SplitsTable`, `LapsGraph`) already had, but it was the one with no header at all.** Matches the
prototype's own `VitalsCard` grouping. Deliberately did **not** build the prototype's two bespoke
gauge visualizations (an externally-scaled HR range bar, a decoupling gradient scale) — both would
need invented numeric thresholds with no existing anchor in the codebase (the frontend's own
decoupling warn threshold, 8%, already disagrees with the backend's `RuleBasedRunInsights::
DECOUPLING_HIGH` at 5%, an existing inconsistency this slice left alone rather than compounding with
a third invented number), and the existing test suite ties `AVG HR`/`MAX HR` to two separate,
independently-asserted tiles — collapsing them into a combined ratio visual would have rewritten
tile-parity test logic for a nice-to-have rather than a ledger-mandated feature. Switched tile `tone`
from `cream` to `sunken` (`bg-line/20`, the variant's own doc comment: "sunken neutral well used
inside chart cards") since the tiles now sit inside a `bg-card` `Card`, not directly on the page.

**`DetailTiles`/`EmptyPanel` both gained a `className`/`as` passthrough**, matching the
`SplitsTable`/`LapsGraph` pattern of the caller passing spacing (`className="mt-10"`) rather than a
wrapping `<section>` in `Show.tsx`. `code-review` caught a real landmark regression this introduced:
the populated branch renders `<Card as="section">` but the empty-tiles branch (`EmptyPanel` →
`LegacyCard`) had no `as` passthrough and silently dropped to a bare `<div>`, losing the region's
`<section>` landmark for a run with no technical data at all. Fixed by adding `as` to `EmptyPanel`
(mirroring `LegacyCard`'s own prop, default `'div'` so all seven other call sites are unaffected) and
passing `as="section"` from `DetailTiles`'s empty branch — locked in by a new test asserting a
`<section>` element renders in that branch, plus two new `EmptyPanel` tests for the default and the
`as` override.

## Files touched

`resources/js/pages/Runs/Show.tsx` (hero stat hierarchy, `DetailTiles` call site), `resources/js/
components/run/DetailTiles.tsx` (+test) (`Card`/`SectionLabel` wrap, `className` prop, `sunken`
tone), `resources/js/components/ui/EmptyPanel.tsx` (+test) (`as` prop, `className` relaxed to
optional). No other file under `components/run/` touched — see "What actually landed" for why each
was left alone.

## Blockers

`F4`, `F5`, `F6`, `L0`. All merged.

## Acceptance criteria

- [x] `L0`'s three named verdicts (Leaflet maps, run lenses, relative effort — all `keep, mechanical
      only`) read directly from the ledger and followed exactly; confirmed already-converged via
      `check:palette` rather than assumed.
- [x] `LapsGraph.tsx`/`SplitsTable.tsx` confirmed F6-owned (`git show ee4f7881 --stat`) and consumed
      as-is, matching `S5`/`S6`'s posture toward their own charts.
- [x] No shipped functionality cut. `AskAboutRun`, `PastYouHero`, `RunHydratingNotice` read against
      the prototype and found already fully converged (Card/SectionLabel/Eyebrow/PillButton/Icon) —
      no design gap, left untouched.
- [x] Hero stat row adopts the prototype's headline/supporting/secondary hierarchy using only
      proven, already-shipped `StatTile` size/tone variants — no new untested variant introduced.
- [x] `DetailTiles` restyled onto the same `Card`+`SectionLabel` language as its sibling "breakdown"
      cards; no invented numeric gauge thresholds added (see rationale above).
- [x] `check-entry-chunks.mjs`'s `Runs/Show` budget (245 kB gz) stays green — 230.7 kB gz, unchanged
      from before this slice (no new dependency, markup-only restructure).
- [x] UI chrome stays Title Case; no em-dashes introduced.
- [x] `/simplify` (reuse/simplification/efficiency/altitude, 4 parallel agents) and `/code-review`
      run against the diff before opening the PR; every finding fixed (see "What actually landed").
- [x] 1:1 test convention: both touched components (`DetailTiles`, `EmptyPanel`) have their
      co-located tests extended, no new `EXEMPT`/`TS_EXEMPT` entries.

## Coverage delta

Frontend: 223 test files / 2154 tests passing (up from 223/2151 pre-slice — 3 new cases across
`DetailTiles.test.tsx` and `EmptyPanel.test.tsx`). Coverage: **95.7% statements / 89.51% branches /
95.56% functions / 96.05% lines**, vs the pre-slice baseline (measured directly via `git stash` on
this branch before any edit) of 95.7% / 89.5% / 95.55% / 96.05% — statements and lines unchanged,
branches and functions +0.01pp each. Flat, as expected for a markup/structure restyle over existing
computed values rather than new logic. Backend: unaffected (no PHP touched); full suite 3737/3737
passing, matching the pre-slice baseline exactly.

## Verification notes

`pest --group=structure --no-tia` (38/38), full `bin pest --parallel --no-tia` (3737/3737, run once
before the frontend-only fixup rounds — no PHP touched by any of them, so re-running per round would
have been redundant per the ladder's own "narrowest thing that can fail" guidance). `npx tsc
--noEmit` clean after every round including the `/simplify`/`/code-review` fixup rounds. `npm run
build && npm run check:chunks` green throughout — **`Runs/Show` 230.7 kB gz against its 245 kB
budget**, unchanged across every round since nothing in this slice added a dependency. Full `npm run
test` 223/223 files, 2154/2154 tests. `npm run test:coverage` hit the same `CardReveal.test.tsx`
host-contention timeout `S10`/`S11` already documented on this shared multi-worktree host (1 file,
1 test, on the default-worker run only); re-ran with `--maxWorkers=2` per the documented fallback.
`check-raw-palette.mjs` clean (469 files, zero off-token utilities, run explicitly against the three
`L0`-mechanical files plus every file this slice touched). `php scripts/check-doc-citations.php`
clean. `resources/brand/grounds.json` needed no regeneration: the two tones this slice newly calls
(`sunken` → `bg-line/20`, `sky` → `bg-cream/[0.06]`/`bg-cream/[0.12]`) were already classified from
other existing call sites (chart cards; `KpiTile`/`VitalChips`), confirmed by grep before concluding
no update was needed.

`/simplify` (4 parallel subagents: reuse, simplification, efficiency, altitude) found one real
finding each in simplification and altitude — reuse and efficiency came back clean. Simplification:
the three secondary hero tiles (HR/TRIMP/elevation) were near-identical copy-paste, varying only in
label/value/unit/sub/explainerKey; extracted to a `secondaryStats` array and `.map()`, removing the
duplication. Altitude: `DetailTiles`' new optional `className` prop was being coerced with `?? ''`
at the `EmptyPanel` call site to paper over `EmptyPanel`'s own stricter-than-necessary required
`className: string` — fixed at the source by relaxing `EmptyPanel`'s prop to optional instead
(every other of its seven call sites already passes a concrete string, so this was never load-bearing
elsewhere). `/code-review` found two real issues: the landmark regression described above, and two
multi-line comments narrating the hero-restyle's design rationale, a direct violation of the
standing "no design-decision narration in code" rule — both comments removed (the rationale already
lives in this doc and the PR description).

## Open questions

None blocking. Two things intentionally deferred, both noted above rather than silently skipped: the
prototype's HR-range and decoupling gauge visualizations were evaluated and declined for this slice
(would require inventing numeric thresholds with no existing anchor, and the decoupling case would
compound an existing frontend/backend threshold mismatch rather than fix it) — a natural follow-up
if a future slice wants to resolve the 8%/5% decoupling-threshold discrepancy between `DetailTiles.
tsx` and `RuleBasedRunInsights::DECOUPLING_HIGH` first, then build a gauge anchored to the resolved
value. No genuine product/architecture fork surfaced during this slice; every call above was a
routine implementation-correctness decision, recorded transparently rather than escalated.
