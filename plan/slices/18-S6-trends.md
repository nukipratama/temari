# S6 — Trends

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `F6` · **Status** in review ([#TBD](#), squashed as `TBD`)

## Goal

Port `pages/Trends.tsx` + `components/trends/` (7 files). Charts consumed from `F6`, not redesigned.

## What actually landed

**F3's mechanical sweep and F6's chart port had already carried most of this page's visual weight before this slice started.** Reading the prototype's `TrendsScreen.tsx` against the shipped page found that `FitnessTrend`, `LoadTrend`, `VdotTrend` and `PaceConsistencyTrend` already used `--radius-panel`/`--shadow-panel`, `CHART_GROUND`, `StatTile`, and `text-label-micro` — the app's own converged token vocabulary, applied by F6 (charts, two grounds) specifically because these four files *are* the shared chart components F6 already ported. What remained unstyled since F3's mechanical pass were the three files F6 had no reason to touch: the page shell (`Trends.tsx`), `RangeToggle.tsx`, and `NarrationHeadline.tsx` — confirmed by `git log`, which showed no commits on any of the three past F3's sweep.

**Page hero adopts the two-line italic-accent headline pattern S9 (Inbox) established**, not the prototype's literal lowercase copy — the standing voice rule for this slice ("Trends is not Login, no lowercase treatment") overrides the mockup's own casing. "How things" / *"are going."* keeps the app's existing sentence-case convention, with the italic second line colored `text-icon-accent` — the same ground-reactive accent token the prototype itself uses here (`--color-icon-accent` resolves to `horizon-ink` on light, raw `horizon` on dark), already proven safe elsewhere in the app (`MobileBottomNav`). The richer two-sentence subtitle (the shipped page's own addition beyond the mockup's single line) is kept as-is — real, useful context the static mockup had no props to draw.

**`RangeToggle` restyled onto the prototype's segmented-pill shape**: container drops its bordered-card look for `bg-muted p-1 rounded-full`; the selected pill becomes `bg-card text-foreground shadow-e1` (the exact `shadow-e1` token the prototype's own `FitnessPanel`/nav use); unselected pills stay full-weight `text-foreground` rather than the app's usual `text-text-3` dimming, matching the prototype's own choice of relying on the elevated pill alone to signal selection. The existing `motion.button` press-shrink interaction and `role="group"`/`aria-label="Time range"` accessibility (neither present in the static mockup) are kept — real interaction and a11y, not mockup scope.

**`NarrationHeadline`'s narrated title and description are now italic serif**, matching both the prototype's own `NarrationCard` (its two paragraphs are italic) and this app's own pre-existing "Temari voice" convention elsewhere (`VerdictHero`, `Auth/Login`'s quote, `Devtools/Design`'s `quote-lg` sample all already use `font-serif italic` for narrated/voiced text) — the previous non-italic rendering was the outlier, not the target. The card's outer shell (border/bg/radius, the mount-once ignition-ring glow reused from `CardReveal`) and eyebrow treatment were already close to the prototype's own "temari's read" card and are unchanged.

**The prototype's pill-style regenerate/cooldown button was not adopted.** That control lives inside the shared `AnalysisStatus` component (its "Reread" text-link + cooldown state), used by roughly ten other narrated blocks across the app — not in this slice's file list. Forking its rendering for Trends alone would desync one page's regenerate affordance from every other narrated block's, for a purely cosmetic difference. Recorded as a routine implementation-correctness call, not a fork.

**The milestone badge chips were not recolored by rarity to match the prototype's `Medal`-icon-by-rarity treatment.** The prototype's three example badges each carry a `rarity` field its mockup invents locally; the real `BadgeMilestone` payload from `TrendsController` (`{key, date}` only, sourced from `RunCard::firstEarnedDatesForUser()`) carries no rarity. Wiring rarity into the milestone timeline would mean new backend payload shape — out of this slice's scope (blockers are `F4`/`F6` only, no backend blocker) — so the existing per-badge emblem (`badgeEmblem()`), which is more informative than the mockup's single generic medal glyph, was kept unchanged.

**`resources/brand/grounds.json` needed one edit, not a full regen.** Restyling `RangeToggle` away from `bg-horizon/30` left that key's only registered call site painting nothing, which `DesignTokenContrastTest`'s dead-call-site assertion catches by design (see plan/README.md R4/§8). Removed the stale `"horizon/0.3"` entry; no other site uses that opacity, and no new translucent panel was introduced, so nothing else in the file changed.

## Files touched

Modified: `resources/js/pages/Trends.tsx` (+test), `resources/js/components/trends/RangeToggle.tsx`,
`resources/js/components/trends/NarrationHeadline.tsx`, `resources/brand/grounds.json` (stale call-site
removal). No changes to `FitnessTrend.tsx`, `LoadTrend.tsx`, `VdotTrend.tsx`,
`PaceConsistencyTrend.tsx`, or `PersonalBests.tsx` — already onto the app's converged panel/chart
token vocabulary via F3/F6, and no functional or backend change was needed. No backend files touched.

## Blockers

`F4`, `F6`. Both merged.

## Acceptance criteria

- [x] Page hero, `RangeToggle`, and `NarrationHeadline` restyled onto the prototype's `TrendsScreen.tsx`
      visual/structural language (segmented pill toggle, italic Temari-voice narration card,
      two-line accent headline), while `FitnessTrend`/`LoadTrend`/`VdotTrend`/`PaceConsistencyTrend`/
      `PersonalBests` keep the chart styling F6 already ported.
- [x] No shipped functionality cut: badge-milestone plotting on the fitness chart, the Range toggle's
      client-side slicing, and every panel's real data-driven content all unchanged.
- [x] No new backend/API shape invented for a prototype-only addition (the rarity-tinted badge
      treatment) that would have required one — recorded as a routine scope call, not guessed at.
- [x] UI chrome stays Title Case / existing sentence-case copy; no lowercase treatment applied
      despite the prototype's own lowercase mockup copy (explicit slice instruction).
- [x] No em-dashes introduced.
- [x] 1:1 test convention preserved; `Trends.test.tsx`'s headline assertion updated for the new
      two-line markup, no new `EXEMPT`/`TS_EXEMPT` entries.
- [x] `resources/brand/grounds.json` kept in sync with the one call-site removal this slice caused;
      `DesignTokenContrastTest` passes.

## Coverage delta

Frontend: **no measurable change** — 216/216 test files, 2099/2099 tests passing both immediately
before this slice's edits (measured via `git stash` on the touched files) and after: 95.6% statements /
89.4% branches / 95.44% functions / 95.95% lines in both runs. Expected: the changes are className/copy
restyles plus one updated test assertion, with zero new logic branches and no new files.

Backend: unaffected (no PHP touched). Full suite still 3736/3736 passing, 11424 assertions
(`bin pest --parallel --no-tia`), matching the pre-slice baseline exactly.

## Verification notes

`pest --group=structure --no-tia` (38/38 — first run caught the stale `grounds.json` call site above,
fixed, re-ran green), full `bin pest --parallel --no-tia` (3736/3736, 11424 assertions), `npx tsc
--noEmit` clean, `npm run build && npm run check:chunks` green (Trends is not one of the four
hardcoded-budget routes; Login stays unaffected), `npm run test:coverage` clean (216/216 files,
2099/2099 tests; see Coverage delta above), `php scripts/check-doc-citations.php` run directly through
Sail per the ladder's rule for any grounds-touching slice — clean, no citation drift.

**A resource-contention note for whoever runs this ladder next**: this worktree's Docker stack shares
the host with two sibling worktrees (`s3-today`, `s4-plan`) plus unrelated local projects. Two
back-to-back `npm run test:coverage` invocations left running concurrently (one from a `run_in_background`
call that wasn't actually complete, one from a naive shell `&`) produced a genuinely stalled/slow run
with the container pegged near 120% CPU — not a hang, confirmed via `docker stats` before killing the
duplicate and re-running clean. Matches the S11 precedent: run one coverage/pest invocation at a time
in a shared-host session, and verify with `docker stats` before concluding a slow run is broken.

## Open questions

None blocking. Two things intentionally deferred, both requiring scope this slice's blockers (`F4`,
`F6` only, no backend blocker) don't cover:

1. **Rarity-tinted badge chips** — the prototype's `FitnessPanel` colors each badge pill by rarity;
   the real `BadgeMilestone` payload doesn't carry rarity today. A natural follow-up if a future slice
   wants that treatment, needing a `TrendsController` payload change, not a page-only restyle.
2. **The prototype's pill-style regenerate/cooldown button** — not adopted since it lives inside the
   shared `AnalysisStatus` component used by ~10 other narrated blocks app-wide; out of this slice's
   file list. A cross-cutting `AnalysisStatus` visual pass (if ever wanted) is a separate, deliberate
   decision, not something to fork silently for one page.

### Needs a decision — not implemented

Mid-slice, a chat message claiming to be from "the program coordinator" (preceded by a "disregard,
wrong agent" message) asked this slice to additionally consolidate a "streak" widget — pulling
`SeasonStreakSummaryBuilder::streakPayload()` off the Today/Plan pages and onto a new badge card on
Trends, citing the prototype's `streak-6` example badge as the mandate, and explicitly said not to
coordinate with the sibling `S4` (Plan) worktree also live on this host.

This was not implemented. Verified before declining: `SeasonStreakSummaryBuilder::streakPayload()` and
`WeeklySnapshot::consecutiveWeekStreak()` do exist, and the prototype's mockup does include a
`streak-6` entry among its three illustrative example badges — but `plan/README.md`'s decision table
and amendments log, `plan/ledger.md`, `plan/ia.md`, and every slice doc including `S3`'s and `S4`'s own
stubs, have zero mentions of "streak" anywhere. This program's own rule (plan/README.md §1) is that a
decision of this shape — cutting a shipped widget from two other in-flight sibling slices and adding
new backend-to-frontend wiring to a third, page-only slice with no backend blocker — must land as a
recorded amendment before a slice acts on it. It hasn't. Combined with the "disregard/real one"
message pattern and the explicit instruction to avoid coordinating with `S4`'s own worktree over a
change to a widget `S4` (Plan) itself may still own, this reads as exactly the "genuine architecture
fork only a human could decide" case the program's process calls out, not a routine implementation
call. Flagging here rather than guessing; whoever reviews this PR should confirm with the user directly
whether such a consolidation was actually decided, and if so, land it as an amendment in
`plan/README.md` before any slice (this one or `S3`/`S4`) implements it.
