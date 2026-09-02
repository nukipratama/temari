# PS8 — Activity detail

**Program** prototype parity · **Slot** 2 (worktree, concurrent with `PS4` and `PS1`) ·
**Blockers** `PP0`-`PP3`, `C1` · **Status** in-review

## Goal

Carry **P28**: rebuild `/activities/{activity}` to the prototype's section list, order and
treatment, at P2 fidelity, against
[reference.md](../reference.md) §10 — plus **P16** (the real Leaflet map keeps the slot the
prototype fills with a decorative placeholder, weather beneath it) and **P19** (run lenses stay).

### The `V0` trap, and why nothing below the fold was cut

`V0` screenshotted the prototype at its fixed 844px frame and reported five sections as "absent"
that [ActivityDetailScreen.tsx](../../../resources/brand/prototype/src/components/pages/ActivityDetailScreen.tsx)
in fact draws. All five stay, and this slice rebuilt rather than removed them: the Q&A panel
(decl 516), the narration card (decl 583), the vitals card (decl 675), the per-km splits chart
(decl 784) and the laps carousel (decl 972). Every section below was implemented by reading that
file, not `reference.md`'s summary of it; where the two are compared below, they agreed.

## What landed

### The section list, in the prototype's order

`Show.tsx` is now a linear column matching [:1030-1062](../../../resources/brand/prototype/src/components/pages/ActivityDetailScreen.tsx):
eyebrow → hydrating notice → hero → past you → what temari says → ask about this run →
"the breakdown" eyebrow → vitals → splits → laps → provenance footer. Two behavioural changes
came with the order:

- **`AskAboutRun` moved below `RunLenses`.** The shipped page put the Q&A panel directly under the
  hero; the prototype puts it after the narration (1047-1048). Temari says her piece, then the
  reader interrogates it.
- **Sections 4-10 are gated on the run being detailed.** The prototype renders none of them under
  `awaitingDetail: 'hydrating'` (1045-1057). The shipped page rendered them always, which meant a
  summary-only run showed a column of empty panels under an honest "still filling this in" notice.

### Four renames, to the prototype's own vocabulary

Each old name became actively wrong once the component was rebuilt, so the file moved with it.
**These are renames, not cuts** — every one still renders, in the same slot:

| was | is | why the old name lied |
|---|---|---|
| `PastYouHero.tsx` | [PastYouCard.tsx](../../../resources/js/components/run/PastYouCard.tsx) | it is no longer in the hero — the prototype puts it in the page column (1041-1043) |
| `DetailTiles.tsx` | [VitalsCard.tsx](../../../resources/js/components/run/VitalsCard.tsx) | it is no longer a tile grid: HR scale bar, three tiles, a gradient marker |
| `SplitsTable.tsx` | [SplitsChart.tsx](../../../resources/js/components/run/SplitsChart.tsx) | it is a bar chart now, not a table of rows |
| `LapsGraph.tsx` | [LapsCarousel.tsx](../../../resources/js/components/run/LapsCarousel.tsx) | it draws no graph — it is scrollable cards |

Plus one genuinely new file, [RunHero.tsx](../../../resources/js/components/run/RunHero.tsx),
extracted so `Show.tsx` stays a section list rather than a 400-line panel.

### Hero stat hierarchy, and the title/pill treatment (P28)

The shipped hero was a sky-gradient `HeroPanel` with six equal `StatTile`s. The prototype's
`HeroPanel` (decl 412) is a **card-toned** panel drawing a hierarchy: distance is the one big mono
figure (442), duration and pace sit beside it at supporting size (453-468), and HR / TRIMP /
elevation are three small `SecondaryStatTile`s (471-475). Identity is date-eyebrow → serif italic
title → mood pill, in that order (423-435) — the shipped page had chip and date *above* the title.
`useCountUp` and the `—` fallbacks survive; they are plumbing, not decoration.

`HeroPanel` (the sky panel) is untouched and still used by Today, Profile and the dashboard cards —
this screen simply stops using it, because the prototype draws this hero on the card surface.

### P16 — the real map, in the placeholder's slot

`MapWeatherPanel` is now one sunken slab: **map first, conditions underneath**, matching
`MapWeatherPanel` (decl 221) — the shipped version had weather above the map. `RouteMap` dropped
its own `rounded-lg border` now that the slab supplies the frame. Its existing tap-to-activate
pill already matches the prototype's "activate map" badge, so P16 needed no new affordance.

### The kartu block is cut; the share button survives

The prototype draws no collectible anywhere ([reference.md](../reference.md) §10 "Explicitly
absent"), so the on-page card, its rarity chip and its lore column are gone. `PP3` had cut the
rarity *explainer* and explicitly left "the card, its flavor quote, the share button" standing;
P28's rebuild takes the first two.

**The share button stays** — [cut-list.md](../cut-list.md) §4's accepted divergence, so a generated
Kartu is viewable at all. It moved into the hero header, renders only when the run actually has a
card (no false-hope affordance), and anchors the page's existing `run-share` coach mark via a
forwarded ref.

That cut orphaned three components, deleted here with their tests: `Kartu`, `KartuMount` and
`ZoneBar` (the last reader of `ZoneBar` was `Kartu`). **Checked before deleting, and worth
restating because these deletions superficially resemble the `V0` trap:** nothing else in
`resources/js` imports any of the three, and `ShareCardModal` does not — it draws on a canvas via
`lib/shareCard.ts`, so **P12's share path is untouched**. `KartuMini` (Login's sample, History's
list rows), `RouteGlyph`, `ThreadBandGlyph` and both share-image renderers all still stand. The
`CardFlavor` analysis is still fetched and still feeds the share image's caption.

### The breakdown

- **`VitalsCard`** draws what the prototype's vitals card draws (decl 675): HR avg as the headline
  with max marked on a 100-190 scale bar, cadence / steepest grade / flat pace as three tiles,
  decoupling as a marker on a leaf→citrus→ember gradient. **P18 holds** — no relative effort, which
  `PP3` had already removed. Every existing data guard survived the rewrite: the ≥3% grade floor,
  the `Number.isFinite` check against a corrupt JSON blob, and the ≥31°C rule that excuses upward
  drift as heat but never a negative decoupling.
- **`SplitsChart`** is the prototype's chart (decl 784) — bar heights, a dashed HR polyline, a
  tap-to-read tooltip that dims the other bars, the trailing partial as a dashed unranked bar, and
  the fastest-km callout. **Bar heights reuse `computeBarWidth`** rather than the prototype's raw
  min→max stretch: the helper's `FULL_SPREAD_SEC` band exists precisely so a run whose kms are a
  second or two apart reads as consistent instead of as a dramatic swing, and a naive port would
  have reintroduced the bug that comment records. The HR trace is drawn only when ≥2 kms recorded
  one — one point is not a line.
- **`LapsCarousel`** is the prototype's side-scrolling cards (decl 972), fastest lap picked out,
  native overflow scroll, no paging buttons.

### `RunLenses` and `AskAboutRun` share one voice surface

The prototype gives both a distinctive card: a 1.5px accent-mixed border plus a horizon halo
(`NARRATION_CARD`, :580-581). That became a `narration` tone on `cardVariants` rather than a
per-file class string, since two components draw it. `RunLenses` collapsed its two panels into one
card split by a hairline, took the `FaceIcon` heading in from `Show.tsx`, and moved its reread
control to a pill at the foot of the card. `AskAboutRun` reordered to thread → starting points →
ask box. All the `AnalysisStatus` plumbing, the chain-head gate, the shared cooldown and every
`useRunQuestions` error path are unchanged.

## Files touched

**Added (5 + 5 tests)**: `components/run/{RunHero,PastYouCard,VitalsCard,SplitsChart,LapsCarousel}.tsx`.

**Deleted (7 + 7 tests)**: `components/run/{PastYouHero,DetailTiles,SplitsTable,LapsGraph}.tsx`
(renamed above), `components/card/{Kartu,KartuMount,ZoneBar}.tsx` (orphaned by the kartu cut).

**Edited — frontend**: `pages/Runs/{Show,useRunShow}.tsx|ts` (+ tests),
`components/run/{AskAboutRun,RunLenses,MapWeatherPanel,RouteMap,RunHydratingNotice}.tsx`,
`components/ui/{Icon,LegacyCard}.tsx`, `lib/variants.ts`, `types/inertia.ts`.

**Generated**: `resources/brand/grounds.json`.

**Docs**: `features/run-detail.md` (rewritten section by section), `features/cards-collection.md`,
`design-tokens.md`, `architecture/geo-reverse-geocoding.md` (its location-consumer citation follows
the read into `MapWeatherPanel`).

**No backend file changed.** `RunController` already ships everything the new sections read.

## Blockers

None. The four foundation slices had left the screen in exactly the state the brief described:
`PP1`'s `PageContainer` needed no per-screen work, `PP2`'s `FaceIcon` placements were correct as
found, and `PP3`'s three cuts were verified done rather than redone.

## Acceptance criteria

- [x] Every section in [reference.md](../reference.md) §10 renders, in that order, with the
      interactions its table lists.
- [x] The five sections `V0` wrongly called absent all render.
- [x] The three genuinely-cut blocks stay cut, and the on-page kartu block goes with them.
- [x] The share button survives (§4 divergence), hidden when the run has no card.
- [x] P16: the real Leaflet map fills the placeholder's slot, weather beneath it.
- [x] P19: run lenses kept, as one card.
- [x] P18: no relative effort; the vitals card draws the prototype's five readings.
- [x] `awaitingDetail` withholds sections 4-10, as the prototype does.
- [x] No breakpoint-prefixed responsive classes added — the 900px step stays `PageContainer`'s.
- [x] Every new component has a co-located test; every deleted one lost its test with it. No
      `EXEMPT` / `TS_EXEMPT` entry added.
- [x] `Runs/Show.tsx` neither moved nor renamed; `check-entry-chunks.mjs`'s four paths all resolve
      and no budget was re-baselined.
- [x] `grounds.json` regenerated; `DesignTokenContrastTest` green both ways.
- [x] No guard weakened, no one-off literal added to accommodate a hardcoded prototype value.

## Coverage delta

Measured on this branch, `epic/mobile-ux-port` at `8415d1f7` vs the slice, same run configuration:

| | before | after |
|---|---|---|
| statements | 97.15% (3962/4078) | **97.21%** (3946/4059) |
| branches | 90.49% (3229/3568) | 90.39% (3211/3552) |
| functions | 96.78% (1052/1087) | **97.03%** (1048/1080) |
| lines | 97.54% (3769/3864) | **97.60%** (3751/3843) |

Flat, on a denominator 19 statements smaller: the five new components land at **98.32%**
statements for `components/run` as a directory, which offsets the well-covered `Kartu`/`ZoneBar`
leaving. Branches slip 0.10pt — `SplitsChart`'s clamp arms and `useRunShow`'s share-payload
null-coalescing chain are the only material gaps, and `Runs/Show.tsx` itself is now 100% statements
/ 100% functions (it was 88.88% / 72.72% after `PP3`, which that slice flagged as `PS8`'s to
raise). Nowhere near the 95% gate.

## Verification notes

`./vendor/bin/sail composer check` — the single gate since `C1`, running exactly what CI runs —
green end to end. Run as `sail exec -e COMPOSER_PROCESS_TIMEOUT=0 app composer check`: the default
300s composer process timeout kills `phpstan` and then `rector` on this machine. `PS4` is landing
the `Composer\Config::disableProcessTimeout` fix in `composer.json`; not duplicated here to avoid
colliding with it.

| step | result |
|---|---|
| `typescript:enums --check` | up to date |
| `check-doc-citations.php` | all citations resolve ✓ (run directly as well as via the gate) |
| `check-see-references.php` | all App references resolve ✓ |
| `pint --test` · `prettier --check` | passed · clean |
| `tsc` · `eslint --max-warnings 0` | clean · clean |
| `check:palette` | 420 files, zero off-token utilities ✓ |
| `phpstan analyse` | 0 errors |
| `rector --dry-run` | 0 changed |
| `pest --parallel` | 3638 passed |
| `npm run test:coverage` | 1938 passed, 97.21% statements |
| `npm run build` + `check:chunks` | within budget on all four routes |

**Entry-chunk budgets — no re-baseline needed.** `Runs/Show` lands at **627.8 kB raw / 206.3 kB
gzipped against its 245 kB budget**, 38.7 kB of headroom. The route's own chunk is 32.55 kB raw /
10.11 kB gzipped; `RouteMap` (1.78 kB) and `ShareCardModal` (22.21 kB) both stay lazy, and `maps`
and `charts` stay out of the closure. The four hardcoded paths in `check-entry-chunks.mjs` all
still resolve — `Runs/Show.tsx` was neither moved nor renamed.

**`grounds.json`** was hand-corrected, not regenerated (it has no generator; the contrast test
names what to add). Three edits, all driven by a test failure message: the laps carousel's
fastest-lap tint joined `horizon/0.1`'s call sites, the `cream/0.04` spec and `cream/0.1`'s two
`card/{Kartu,ZoneBar}` entries came out as stale, and the vitals max-HR tick moved off `bg-text-2`
onto `bg-foreground` rather than classifying a *text* token as a ground.

**Not verified in a browser.** No visual sweep was run — `/browser-review` needs a build served
from this worktree's own stack and the change is large enough that a reviewer's eye is worth more
than one screenshot round. The PR body lists what to look at.

## Open questions

1. **Where the share button belongs is this slice's own call.** The prototype draws no share
   button anywhere, so §4 grants the button but names no slot. It went in the hero header,
   right-aligned beside the identity block: discoverable, adjacent to the run it shares, and the
   only place on the page that is not a prototype-specified section. A reviewer who wants it
   elsewhere (the topbar, beside the footer) is overruling a placement, not a decision.
2. **The map slot is 280px tall, not the prototype's 130px.** The prototype's 130px is sized for a
   decorative squiggle; a 130px Leaflet viewport is unusable. `RouteMap`'s existing height is
   unchanged rather than re-tuned to an invented number. Worth a look on `se` (320px), where the
   hero is now the tallest panel on the page.
3. **The mood pill keeps the app's mood-tinted fill**, where the prototype uses a neutral
   `bg-muted` chip with a mood dot. `MoodChip` is shared with History and the dashboard, so
   neutralising it here would either fork the component or change three other screens; P28's
   "tag-pill / mood-icon treatment" was read as the ordering and placement change, not a
   repaint. Flagged in case `PS7`/`PS3` reach the opposite reading.
4. **`Kartu`, `KartuMount` and `ZoneBar` are deleted, not deferred to `W2`.** `W2`'s remit is
   orphaned *backend* models, tables, jobs and services; `PP3`'s precedent for frontend components
   its own cut orphaned (`PackWrapper`, `ConfettiBurst`) was to delete them with their tests. If a
   later screen slice turns out to want a full-face card, it is one `git revert` of a file away.
