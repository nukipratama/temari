# S6 — Trends

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `F6` · **Status** merged ([#667](https://github.com/nukipratama/temari/pull/667), squashed as `adea9b6d`)

## Goal

Port `pages/Trends.tsx` + `components/trends/` (7 files). Charts consumed from `F6`, not redesigned.

## What actually landed

**F3's mechanical sweep and F6's chart port had already carried most of this page's visual weight before this slice started.** Reading the prototype's `TrendsScreen.tsx` against the shipped page found that `FitnessTrend`, `LoadTrend`, `VdotTrend` and `PaceConsistencyTrend` already used `--radius-panel`/`--shadow-panel`, `CHART_GROUND`, `StatTile`, and `text-label-micro` — the app's own converged token vocabulary, applied by F6 (charts, two grounds) specifically because these four files *are* the shared chart components F6 already ported. What remained unstyled since F3's mechanical pass were the three files F6 had no reason to touch: the page shell (`Trends.tsx`), `RangeToggle.tsx`, and `NarrationHeadline.tsx` — confirmed by `git log`, which showed no commits on any of the three past F3's sweep.

**Page hero adopts the two-line italic-accent headline pattern S9 (Inbox) established**, not the prototype's literal lowercase copy — the standing voice rule for this slice ("Trends is not Login, no lowercase treatment") overrides the mockup's own casing. "How things" / *"are going."* keeps the app's existing sentence-case convention, with the italic second line colored `text-icon-accent` — the same ground-reactive accent token the prototype itself uses here (`--color-icon-accent` resolves to `horizon-ink` on light, raw `horizon` on dark), already proven safe elsewhere in the app (`MobileBottomNav`). The richer two-sentence subtitle (the shipped page's own addition beyond the mockup's single line) is kept as-is — real, useful context the static mockup had no props to draw.

**`RangeToggle` restyled onto the prototype's segmented-pill shape**: container drops its bordered-card look for `bg-muted p-1 rounded-full`; the selected pill becomes `bg-card text-foreground shadow-e1` (the exact `shadow-e1` token the prototype's own `FitnessPanel`/nav use); unselected pills stay full-weight `text-foreground` rather than the app's usual `text-text-3` dimming, matching the prototype's own choice of relying on the elevated pill alone to signal selection. The existing `motion.button` press-shrink interaction and `role="group"`/`aria-label="Time range"` accessibility (neither present in the static mockup) are kept — real interaction and a11y, not mockup scope.

**`NarrationHeadline`'s narrated title and description are now italic serif**, matching both the prototype's own `NarrationCard` (its two paragraphs are italic) and this app's own pre-existing "Temari voice" convention elsewhere (`VerdictHero`, `Auth/Login`'s quote, `Devtools/Design`'s `quote-lg` sample all already use `font-serif italic` for narrated/voiced text) — the previous non-italic rendering was the outlier, not the target. The card's outer shell (border/bg/radius, the mount-once ignition-ring glow reused from `CardReveal`) and eyebrow treatment were already close to the prototype's own "temari's read" card and are unchanged.

**The prototype's pill-style regenerate/cooldown button was not adopted.** That control lives inside the shared `AnalysisStatus` component (its "Reread" text-link + cooldown state), used by roughly ten other narrated blocks across the app — not in this slice's file list. Forking its rendering for Trends alone would desync one page's regenerate affordance from every other narrated block's, for a purely cosmetic difference. Recorded as a routine implementation-correctness call, not a fork.

**The milestone badge chips were not recolored by rarity to match the prototype's `Medal`-icon-by-rarity treatment.** The prototype's three example badges each carry a `rarity` field its mockup invents locally; the real `BadgeMilestone` payload from `TrendsController` (`{key, date}` only, sourced from `RunCard::firstEarnedDatesForUser()`) carries no rarity. Wiring rarity into the milestone timeline would mean new backend payload shape — out of this slice's scope (blockers are `F4`/`F6` only, no backend blocker) — so the existing per-badge emblem (`badgeEmblem()`), which is more informative than the mockup's single generic medal glyph, was kept unchanged.

**`resources/brand/grounds.json` needed two edits, not a full regen.** Restyling `RangeToggle` away from `bg-horizon/30` left that key's only registered call site painting nothing, which `DesignTokenContrastTest`'s dead-call-site assertion catches by design (see plan/README.md R4/§8). Removed the stale `"horizon/0.3"` entry; no other site uses that opacity. `StreakBadge.tsx` (below) reuses `FitnessTrend.tsx`'s own `bg-horizon/25` badge-chip treatment, which added a second registered call site under the existing `"horizon/0.25"` key rather than a new key.

**Streak became a Trends badge-board entry, per the 2026-08-30 amendment recorded in `plan/README.md` §5** ("Streak feature redesign", `epic/mobile-ux-port@b13e9afb`). This was originally relayed mid-session as an unverified chat claim with no paper trail anywhere in `plan/` — correctly declined at the time (see git history on this branch) until the coordinator pushed the actual amendment commit to `origin/epic/mobile-ux-port`, which this branch then merged in before implementing. `SeasonStreakSummaryBuilder::streakPayload()` — already wired into `ProfileController` and `PlanController` the same way — is now also called from `TrendsController::__invoke()` and passed as a new `streak` Inertia prop. A new `StreakBadge` component renders it as its own badge-board card: a clickable pill (weeks count + a `mdi:medal-outline` glyph, deliberately not the flame reserved for the prototype's tempo-session day-glyph convention elsewhere) that expands on tap into a plain-language detail line, mirroring the prototype's `TrendsScreen.tsx:55-59` badge-chip + expand pattern. It is **not** folded into `FitnessTrend`'s `milestones` prop: that list plots badges at a specific historical chart date (`RunCard::firstEarnedDatesForUser()`), while the streak is a live, always-current fact with no single earned-on date — so it renders as its own card, right after `FitnessTrend`, outside the date-range-scoped chart flow (matching how `PersonalBests` already sits outside that flow too). The `rarity: 'uncommon'` styling on the chip is a hardcoded, page-local constant (the medal glyph's ink color) — not new backend rarity infrastructure, since the amendment's own instruction pointed at that literal prototype line as the treatment to match, unlike the general milestone-badge rarity question already declined above for lacking a real data source.

The `StreakSummaryLike` type is defined locally in `StreakBadge.tsx` rather than imported from `components/plan/StreakPanel.tsx`'s existing `StreakSummary` export, even though the shapes are identical — `S4` (Plan) is removing that file in the same wave, so importing from it would create a cross-slice coupling on a file scheduled for deletion in a sibling worktree.

## Files touched

Modified: `resources/js/pages/Trends.tsx` (+test), `resources/js/components/trends/RangeToggle.tsx`,
`resources/js/components/trends/NarrationHeadline.tsx`, `resources/brand/grounds.json` (two call-site
edits), `app/Http/Controllers/TrendsController.php` (+test, new `streak` prop). No changes to
`FitnessTrend.tsx`, `LoadTrend.tsx`, `VdotTrend.tsx`, `PaceConsistencyTrend.tsx`, or `PersonalBests.tsx`
— already onto the app's converged panel/chart token vocabulary via F3/F6, no functional change needed.

New: `resources/js/components/trends/StreakBadge.tsx` (+test).

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
      two-line markup, `StreakBadge.tsx` and `TrendsController`'s streak wiring both get their own
      co-located/dedicated tests, no new `EXEMPT`/`TS_EXEMPT` entries.
- [x] `resources/brand/grounds.json` kept in sync with both call-site edits this slice caused;
      `DesignTokenContrastTest` passes.
- [x] Streak badge-board entry (`plan/README.md` §5, 2026-08-30 amendment): `SeasonStreakSummaryBuilder::streakPayload()`
      wired into `TrendsController`, rendered via `StreakBadge` matching the prototype's badge-chip
      treatment with a non-flame glyph; never surfaces another user's streak (tested).

## Coverage delta

Frontend: 217/217 test files, 2106/2106 tests passing (up from 216/2099 pre-slice — +1 file, +7 tests:
`StreakBadge.test.tsx`'s 6 cases plus 1 new `Trends.test.tsx` case). **95.61% statements / 89.43%
branches / 95.45% functions / 95.96% lines**, vs the pre-slice baseline of 95.6% / 89.4% / 95.44% /
95.95% (small net-positive across all four — `StreakBadge.tsx`'s own coverage from its dedicated test
outweighs the marginal branches the page-shell restyle didn't add).

Backend: full suite 3739/3739 passing (up from 3736 pre-slice, +3 tests: `TrendsControllerTest`'s new
streak assertions), 11450 assertions (`bin pest --parallel --no-tia`).

## Verification notes

`pest --group=structure --no-tia` (38/38 — first run caught the stale `grounds.json` call site, fixed,
re-ran green; a later run after the streak feature added a second `DesignTokenContrastTest` failure for
`StreakBadge.tsx`'s reused `bg-horizon/25`, fixed the same way), full `bin pest --parallel --no-tia`
(3739/3739, 11450 assertions), `npx tsc --noEmit` clean, `npm run build && npm run check:chunks` green
(Trends grew 25.52→27.20 kB gz; not one of the four hardcoded-budget routes, Login unaffected),
`php scripts/check-doc-citations.php` run directly through Sail per the ladder's rule for any
grounds-touching slice — clean, no citation drift.

**`npm run test:coverage` needed three attempts to get a clean read**, all due to real, confirmed host
contention, not a code problem: attempt 1 hit two accidental concurrent duplicate invocations from
earlier in the session (fixed by killing the stray process and confirming via `docker stats`); attempts
2 and 3 (after the streak feature was added) each surfaced 1-3 failures in `AppShell.test.tsx` and
`CardReveal.test.tsx` — files this slice never touches — all `waitFor`/`findByText` timeouts, with a
different subset failing each run. Confirmed as flaky-under-load rather than a real regression by
re-running both files in isolation (35/35 passing) while a sibling worktree (`s4-plan`, then
`s3-today`) was independently spiking to 200-500%+ CPU per `docker stats`. The clean, reported numbers
above came from a `--maxWorkers=2` run (the ladder's documented fallback), which finished 217/217 with
zero failures. Matches the S11 precedent for this exact failure shape on this shared host.

## Open questions

None blocking. One thing intentionally deferred, requiring scope this slice's blockers (`F4`, `F6`
only, no backend blocker) don't cover:

- **Rarity-tinted milestone badge chips** — the prototype's `FitnessPanel` colors each *date-anchored*
  badge pill by rarity; the real `BadgeMilestone` payload (`RunCard::firstEarnedDatesForUser()`)
  doesn't carry rarity today. Unlike the streak badge above (a single hardcoded page-local rarity
  constant, matching an explicit instruction pointed at one literal prototype line), extending this to
  every real earned-badge slug would mean a genuine `TrendsController`/backend payload change — a
  natural follow-up for a future slice, not a page-only restyle.

The prototype's pill-style regenerate/cooldown button on `NarrationHeadline` was also not adopted, for
the reason recorded above (lives in the shared `AnalysisStatus`, used by ~10 other narrated blocks,
out of this slice's file list) — this one is a routine scope call, not an open question.

### Resolved: the streak consolidation flag

An earlier version of this doc flagged a "needs a decision" item here: a mid-session chat message,
preceded by a "disregard, wrong agent" preamble, asked this slice to consolidate a streak widget onto
Trends, with no corresponding entry anywhere in `plan/`. That was correctly declined at the time per
the program's own rule that such a decision must land as a recorded amendment before a slice acts on
it — see this branch's git history for the original reasoning, preserved rather than deleted.

The coordinator subsequently pushed the actual amendment to `origin/epic/mobile-ux-port`
(`b13e9afb`, "docs(plan): record the streak-redesign decision (S3/S4/S6)", now in `plan/README.md` §5)
under the real repo owner's GitHub identity — verified directly by fetching and reading the commit
before merging it into this branch and proceeding. See "What actually landed" above for what was built.
