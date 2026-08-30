# S10 — Profile

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `F5` · **Status** in-review

## Goal

Port `pages/Profile.tsx` (490 L) + `components/me/`.

**Ledger rulings (final)**: persona mix (`PersonaBar.tsx`) gets a real **restyle** — a distinctive
identity feature worth deliberate treatment. Badge/milestone display also gets a **restyle**, split
with `S3` which owns where unlock toasts fire; this slice owns how milestones/badges *display* on
Profile. Note: Accessories itself is **cut** — nothing here should assume an equip-locker link exists
on Profile any more.

## What actually landed

**Persona mix (`PersonaBar.tsx`) restyled onto the prototype's own time-in-zone bar treatment.**
The prototype's `ProfileScreen.tsx` has no persona-mix equivalent to port literally, so the redesign
target was its adjacent zone bar (`ZONES` in `HeroPanel`): gapped, individually-rounded segments
rather than one continuous track, and a chip-style legend (mood dot + label + percent) instead of a
plain text row. Legend chips use `MOOD_SOFT_FILL` (a token already defined for exactly this "chip
background where text sits on top" case) on paper, and a translucent cream chip when `onSky` — the
component's primary real-world mount is inside Profile's dark hero panel, so the onSky path got its
own tuned treatment rather than reusing the pastel mood tints meant for a light ground.

**Badge/milestone display: no separate showcase was built, and that is the finding, not a gap.**
Reading `docs/features/gamification.md` and `docs/features/profile.md` end to end before touching
anything found that the standalone badge board already retired onto `/trends` (`S6`, absorbing
`FitnessTrend`'s badge markers and the new `StreakBadge` badge-board entry) well before this slice
started, and unlock celebrations already fire as a toast (`AppShell`, confirmed out of `S3`'s scope)
and an inbox notification (`S9`). The shipped Profile page has never rendered PR cards or an
accessory strip (`docs/features/profile.md`'s own "Not on this page" section says so), and the
frozen prototype's `ProfileScreen.tsx` draws no badge/milestone showcase either — so there was no
real display gap left on this page to fill with new structure, and decision 5 rules out inventing
one that would need new backend shape the ledger never asked for. What Profile already had that is
genuinely milestone-adjacent — `SeasonStreakPanel`'s season-goal progress bars and streak count — is
what got the real design pass instead: the streak tile now carries the same `mdi:medal-outline`
glyph as Trends' `StreakBadge` (not the flame reserved for the prototype's tempo-session day-glyph
convention), tying the two surfaces together visually since they render the exact same underlying
metric; a completed season goal gets an inline `mdi:check-circle` glyph, the closest honest "you
earned this" cue the existing `is_completed` field supports without a backend change. Recorded here
transparently as a routine implementation-correctness call, matching the precedent `S3` and `S6` set
for the same ledger coupling note ("UnlockToast needed no action" / no rarity-tinted badge chips
without a real data source).

**Accessories cut from `MeTabs`.** `MeTab` narrowed from three variants to `'profile' | 'settings'`;
the tab entry, its icon, and its test coverage are gone. The `/accessories` page, route and
controller themselves are untouched — full removal is `W1`/`W2`'s job per the ledger's own
coordination note ("resolve when W2 starts — do not delete blind"), so `Collection/Accessories.tsx`
still renders (reachable only by direct URL now) with its own `<MeTabs active="profile" />` call
updated to stop claiming a tab that no longer exists, and its own test updated to match. Two other
files broke from the same `MeTab` type narrowing and needed the same mechanical ripple fix:
`Settings/Index.test.tsx`'s nav assertion (dropped the Accessories link check) — `Settings/Index.tsx`
itself needed no change, it already only ever passed `active="settings"`.

**No changes needed to `StravaSyncBadge.tsx`**, despite being named in this slice's file list. It
isn't rendered on Profile at all — it's a `TopNav`/`MobileTopBar` component, grouped into the
ledger's "Badge / milestone system" file list only because its name matched a `Badge`-related grep
sweep during the wave-0 reconciliation pass. It already uses the converged semantic token system
(`bg-sky/[0.06]`, `text-leaf-ink`/`text-ember-ink`/`text-horizon-ink`), so there was nothing to
restyle.

**Everything else on the page was left alone.** The hero panel (mascot, AI profile voice, stat
tiles), the race-CTA `LinkCard`, the training-pace `StatTile` grid, and the progression/journey chart
section all already route through shared, already-converged components (`HeroPanel`, `StatTile`,
`Card`, `LinkCard`) — the same finding `S11` made for Settings' already-swept card sections. No cuts,
no logic changes anywhere on `Profile.tsx` itself; the diff there is limited to the persona-bar
restyle already covered by `PersonaBar.tsx`'s own change and the nav's Accessories removal.

## Files touched

Modified: `resources/js/components/PersonaBar.tsx` (+test), `resources/js/components/me/MeTabs.tsx`
(+test), `resources/js/components/me/SeasonStreakPanel.tsx` (+test),
`resources/js/pages/Profile.test.tsx` (nav assertion only), `resources/js/pages/Collection/
Accessories.tsx` (+test, `MeTabs` call site fix), `resources/js/pages/Settings/Index.test.tsx` (nav
assertion only), `resources/brand/grounds.json` (one new translucent-panel call site registered),
`docs/features/profile.md`, `docs/features/targets-accessories.md`, `docs/features/settings.md`.

No backend files touched — nothing in this slice's scope needed a PHP change (Accessories' own
backend removal is `W1`/`W2`'s job, not this slice's).

## Blockers

`F4`, `F5`. Both merged.

## Acceptance criteria

- [x] Persona mix (`PersonaBar.tsx`) gets a real, deliberate restyle — not a mechanical token swap —
      matching the prototype's own time-in-zone bar treatment (gapped rounded segments, chip legend).
- [x] Badge/milestone display ruling satisfied: investigated end to end against `docs/features/
      gamification.md` and the actual shipped architecture before concluding no separate showcase was
      missing; `SeasonStreakPanel` (the page's genuinely milestone-adjacent surface) got the real
      design pass instead, recorded transparently rather than silently skipped.
- [x] Accessories tab cut from `MeTabs`; nothing on Profile assumes an equip-locker link exists.
      `/accessories` route/controller/page themselves untouched, per the ledger's own "resolve at
      W1/W2" coordination note.
- [x] No em-dashes introduced in any new/changed UI copy.
- [x] UI chrome stays Title Case — Profile is not Login, no lowercase treatment applied.
- [x] `resources/brand/grounds.json` reconciled for the one new translucent panel call site
      (`cream/0.08` on `PersonaBar.tsx`'s onSky legend chip); `DesignTokenContrastTest` passes.
- [x] `npm run build && npm run check:chunks` green — Profile is one of the four hardcoded-budget
      routes (`scripts/check-entry-chunks.mjs`, 230 kB cap); this slice's changes keep it at 203.3 kB
      gzipped, comfortably under.
- [x] 1:1 test convention: every changed component has its co-located test updated or extended; no
      new `EXEMPT`/`TS_EXEMPT` entries.
- [x] Docs kept fresh in the same PR: `profile.md`, `targets-accessories.md` and `settings.md` all
      corrected for the two-tab `MeTabs` and the "milestone display already satisfied" finding.

## Coverage delta

Frontend: 218/218 test files, 2118/2118 tests passing. **95.62% statements / 89.38% branches /
95.47% functions / 95.98% lines**, vs the pre-slice baseline `S6` reported (last screen slice merged
before this one): 95.61% / 89.43% / 95.45% / 95.96% — statements +0.01pp, functions +0.02pp, lines
+0.02pp, branches −0.05pp. The repo's actual coverage gate (`vitest.config.ts`) is lines/functions
only (95% each), both of which improved; the small branch dip traces to `SeasonStreakPanel.tsx`'s
new `is_completed` icon conditional (90.9% branch coverage on that file, lines 74/112 — the
"completed" true-branch is exercised by the new dedicated test, but not every permutation of the
`isLive`/`is_completed` combination is), not a coverage regression on anything this slice removed.

Backend: unaffected (no PHP touched). Full suite still 3737/3737 passing, matching the pre-slice
baseline exactly (`bin pest --parallel --no-tia`).

## Verification notes

`pest --group=structure --no-tia` (38/38 — first run caught the new unregistered `cream/0.08` @
`PersonaBar.tsx` translucent-panel call site via `DesignTokenContrastTest`, fixed by registering it
in `grounds.json` with `ink-on-sky` added to that key's text array, re-ran green), full `bin pest
--parallel --no-tia` (3737/3737, matches baseline), `npx tsc --noEmit` clean (caught the `MeTab` type
narrowing breaking `Collection/Accessories.tsx`'s `active="accessories"` call site, fixed), full
`npm run test` (218/218 files, 2118/2118 tests — caught the same `MeTab` narrowing breaking two nav
assertions in `Collection/Accessories.test.tsx` and `Settings/Index.test.tsx`, both updated to assert
the tab is simply absent rather than active), `npm run build && npm run check:chunks` green (Profile:
203.3 kB gz against its 230 kB budget), `check-raw-palette.mjs` clean (459 files scanned, zero
off-token utilities), `php scripts/check-doc-citations.php` clean (run directly per the ladder's rule
for any grounds-touching slice).

**`npm run test:coverage` needed two attempts, both due to real host contention, not a code
problem** — matches the exact failure shape `S6`/`S11` already documented on this shared multi-
worktree host. Attempt 1 (default worker count) failed 2 test files / 3 tests, all in
`CardReveal.test.tsx` (a `findByText` timeout mid-confetti-animation render) and one other file this
slice never touches; re-run with `--maxWorkers=2` (the ladder's documented fallback) came back fully
clean: 218/218 files, exit 0, coverage numbers above.

## Open questions

None blocking. One thing worth flagging for whoever picks up `W1`/`W2`: `Collection/Accessories.tsx`
now renders `<MeTabs active="profile" />` as a stopgap (there is no longer an "accessories" tab value
to be active) — a minor, clearly-commented cosmetic mismatch (Profile reads as the active tab while
actually standing on Accessories) that only matters until that page is deleted outright in `W2`, so
it wasn't worth a deeper fix in a slice whose ledger explicitly assigns that page's real removal
elsewhere.
