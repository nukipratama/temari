# S12 — Undrawn survivors

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4` · **Status** in review ([#674](https://github.com/nukipratama/temari/pull/674), slot 3)

## Goal

Devtools, Devtools/Design, Legal, AiUsage (+10 `aiusage/` components) — the screens the prototype
never drew at all and the slice programs like this forget. `resources/js/pages/Devtools/Design.tsx`
renders the live token audit out of `lib/designTokens.ts` and was flagged as a candidate to break the
moment `F2` lands; this slice is where that risk gets investigated and closed out, rather than
silently left red.

**Ledger ruling (final)**: these four get a real **restyle** pass, not just a mechanical re-skin —
upgraded from the original mechanical-only assumption. **Accessories is no longer part of this
slice** — the ledger ruled it `cut`; its removal is `W1` (routes) + `W2` (backend/components), not a
restyle here.

## What actually landed

**`Devtools/Design.tsx` was never actually broken by `F2` — investigated and confirmed sound, not
patched.** The slice brief flagged this page as a risk ("breaks the moment F2 lands"), so before
touching anything the actual post-F2 compiled output was inspected directly rather than trusting the
risk note at face value (per the standing "verify, don't trust comments/docs" habit). Reading the
real built `public/build/assets/app-*.css` shows the two-ground override compiles to a plain,
unlayered `[data-theme=dark]{ --color-background:#0b1017; ... }` rule with literal hex values, sitting
after the base `:root,:host{ ... }` block in source order — no `var()` indirection, no `color-mix()`,
nothing the kind of regex/string parsing [R1](../README.md#r1) warns about. `designTokens.ts`'s
`collectTokenNames` only needs token *names* (harvested from the `:root` rule via a `:root`-only
regex) and `readTokenValues` resolves each name's *value* through the browser's own
`getComputedStyle`, which correctly cascades whichever `data-theme` is active — so the page was
already ground-correct by construction, most likely as a side effect of `F2`/`F3`'s own work on
`designTokens.ts` (git history shows both touched it after `F2` landed: `f54441a1`, `9dd7ff2a`,
`6628ff0e`, `0d4b59d4`). Confirmed against a real headless Chromium render of `/devtools/design` with
`data-theme` toggled between `dark`/`light`/unset: no console errors, `--color-background` resolves to
the correct literal value per ground, and the contrast/surface audit chips render sane counts on both.
The only actual defect found on this page was a UI-copy one: two em-dashes in the "Contrast audit"
section's `note` string (not the exempt `'—'` null-placeholder glyph), fixed to a parenthetical.

**`Devtools.tsx` had a real ground-reactivity bug, unrelated to the flagged page.** Its container and
link cards used raw palette tokens (`bg-cream-deep`, `bg-cream`, `border-cream-deep`) instead of the
two-ground semantic layer (`bg-background`, `bg-card` via `cardVariants`) every other page in the app
uses post-`F2`. Raw `cream`/`cream-deep` never invert on the dark ground, so this hub page would have
rendered a stuck-light background under a user's dark-ground choice — the exact class of bug `F2`'s
semantic layer exists to prevent, just on a page nobody had swept yet. Fixed by switching to
`bg-background`/`text-foreground` and reusing `cardVariants` (the same variant matrix `LinkCard`
itself is built on) on the existing plain `<a>` tags. Plain `<a>`, not the `LinkCard` component, is
deliberate: two of the four destinations (`/horizon`, `/pulse`) are non-Inertia vendor dashboards, and
`LinkCard` hardcodes Inertia's `<Link>`, which would break navigation to a non-Inertia response.
Typography also brought into line with its two devtools siblings (`Design.tsx`'s own h1, `AiUsage.tsx`
after this slice) — `font-serif italic text-headline-xs`, replacing an oversized `text-display-xs`
that didn't match either sibling.

**`AiUsage.tsx`'s header brought onto the same `font-serif italic text-headline-xs` treatment.**
Previously plain sans-serif, semibold, inconsistent with `Design.tsx`'s own h1 despite being the same
devtools-console family. This is the only change to `AiUsage.tsx` itself — its `PageContainer` +
`SectionHeading` + `DataTable` composition (all pre-existing) was already the same idiom the rest of
the app's restyled screens converge on, so nothing else needed touching.

**`AttentionArea.tsx` and `FlashBanner.tsx`'s hand-rolled panels moved onto `LegacyCard`.** Both used a
bespoke `rounded-lg border border-border bg-popover p-4` treatment instead of the app's actual card
corner/elevation scale (`rounded-md` + `shadow-e1`, documented in the temari skill as "the card
corner"). Wrapped in the existing `LegacyCard` primitive (`tone="card" padding="panel"`) instead of a
one-off `<div>` — a real reuse win, not just a class swap, since `LegacyCard` is the same
`cardVariants`-backed component ten other already-merged, already-restyled screens use (`TodaySession`,
`WeekPlanWidget`, `InboxRow`, `Profile.tsx`, etc.). No logic touched in either file.

**`LegacyCard` and shadcn `Card` (`components/ui/card.tsx`) were left coexisting on purpose — a
routine call, not a fork requiring a decision.** Both are current, actively-used systems across
already-merged wave-2b slices (`Profile.tsx` uses both; `S9`'s `InboxRow` and `S3`'s
`TodaySession`/`WeekPlanWidget` use `LegacyCard`; `S11`'s `Settings/Index.tsx` and `S5`'s `Race.tsx`
use shadcn `Card`), so "legacy" in the filename is not a deprecation signal and migrating the AiUsage
family off it was never in scope — `BudgetGauge.tsx` and `UsageFilters.tsx` already use `LegacyCard`
correctly and needed no change.

**`Legal/Document.tsx` and the remaining eight `aiusage/` components needed no restyle changes at
all.** Read in full against the type-scale table and semantic-token vocabulary in
`docs/design-tokens.md`: `Legal/Document.tsx`'s header/section hierarchy already matches the
documented `font-serif text-display-lg` / `font-serif text-headline-sm` convention exactly, and
`DailyChart.tsx`, `DeploymentTable.tsx`, `KindTable.tsx`, `UserTable.tsx`, `UsageKpis.tsx`,
`UsageFilters.tsx`, `EmptyState.tsx` all already compose from the shared `DataTable`/`Card`/
`LegacyCard`/`KpiTile`/`ProgressBar`/`SectionHeading` primitives with zero raw-palette or off-scale
utilities. Not every screen in a "real restyle pass" slice needs an equal-sized diff — these were
already fully converged before this slice started, most likely as a side effect of `F3`'s mechanical
sweep plus incidental care when each was originally built.

**No `Accessories` references exist anywhere in these four screens** (verified directly by grep
across all target files, zero hits) — the ledger's `cut` ruling for Accessories required no action
here.

## Files touched

Modified only, no new files: `resources/js/pages/Devtools.tsx`, `resources/js/pages/Devtools/Design.tsx`
(one `note` string, em-dash fix only), `resources/js/pages/AiUsage.tsx` (h1 className only),
`resources/js/components/aiusage/AttentionArea.tsx`, `resources/js/components/aiusage/FlashBanner.tsx`.

## Blockers

`F4`. Merged.

## Acceptance criteria

- [x] All four screens received genuine design attention onto the established token/component
      vocabulary, at the same level of care as a screen slice with an actual prototype spec — bounded
      to real, evidenced gaps rather than padding the diff (see "What actually landed").
- [x] `Devtools/Design.tsx` verified against the post-`F2` token shape on both grounds — via direct
      inspection of the compiled CSS cascade and a real headless-Chromium render with `data-theme`
      toggled, not assumed from the risk note. No logic fix was needed; one em-dash copy fix was.
- [x] Accessories not referenced anywhere in scope (verified by grep, zero hits).
- [x] No em-dashes in UI copy (one real violation found and fixed — the null-placeholder `'—'` glyph
      hits in `DeploymentTable`/`KindTable`/`Design.tsx`'s ratio column are the documented exemption,
      left alone).
- [x] UI chrome stays Title Case — none of these four screens are Login, no lowercase treatment
      applied anywhere.
- [x] Real functionality preserved throughout: Devtools' operator links, the Legal document renderer's
      actual legal content, AiUsage's usage/cost dashboards and recovery actions — every change in
      this slice is `className`/JSX-structure only, no logic, no props, no copy other than the one
      em-dash fix.
- [x] 1:1 test convention respected: no new files, so no new tests required; every existing test for
      the five touched files re-verified against the changed markup and still passes.

## Coverage delta

Backend: unaffected (no PHP touched). Full suite still passing at the pre-slice baseline
(`bin pest --parallel --no-tia`).

Frontend: 223/223 test files, 2148/2148 tests passing (all pre-existing — this slice added no new
test files). Coverage: **95.7% statements / 89.5% branches / 95.55% functions / 96.05% lines**
(4748/4961, 3967/4432, 1291/1351, 4484/4668), against S11's own last-recorded baseline of 95.56%/
89.32%/95.40%/95.92% — a small net-positive shift consistent with every prior slice in this wave (no
new logic branches were introduced; the touched lines were already covered by each file's existing
tests). An attempt to measure the *exact* immediately-prior-commit baseline directly (stashing this
slice's diff and re-running coverage) hit two unrelated flaky failures under concurrent host load
(`AppShell.test.tsx`, `CardReveal.test.tsx` — both timing-sensitive, neither touched by this slice; see
Verification notes), so the comparison above uses S11's own recorded number rather than a
freshly-measured one that would have needed a third, contention-free run to trust.

## Verification notes

`pest --group=structure --no-tia` (38/38 — TIA correctly flagged `DesignTokenContrastTest`,
`DesignTokenMirrorsTest`, `DesignTokenDocsTest`, `NoEmDashInPromptsTest`, `EveryClassHasATestTest` as
affected by this slice's diff, matching plan/README.md §8's coupling table exactly), full
`bin pest --parallel --no-tia` (green, matches pre-slice baseline), `npx tsc --noEmit` clean,
`npm run build && npm run check:chunks` green (Login unaffected at 146.6 kB gz vs its 160 kB cap; this
slice touches no route-budget-tracked entry), `npm run test:coverage` clean (223/223, 2148/2148),
`check:palette` clean (469 files scanned, zero off-token utilities — expected, since every file in
scope was already token-clean before this slice), `php scripts/check-doc-citations.php` clean (run
directly per the ladder's rule for any token/grounds-touching slice; `resources/brand/grounds.json`
needed no regeneration since this slice added no new panel background call site — `bg-background`/
`bg-card` are pre-registered solid semantic tokens, not new alpha-tinted panels).

**A real self-inflicted process note, not a codebase one**: partway through this slice, file edits
were accidentally applied against the *main checkout* (`/Volumes/Code/personal/temari/...`) instead
of this worktree (`/Volumes/Code/personal/temari/.claude/worktrees/s12-undrawn-survivors/...`) —
`EnterWorktree` had refused to switch (the session's cwd was the repo root, not already inside a
worktree), so the fallback was `cd` inside Bash calls, but the Read/Edit/Write tool calls kept using
absolute paths rooted at the main checkout by mistake. Caught before anything was committed: a full
verification round had quietly passed against the *unmodified* worktree (the container's bind mount
never saw the edits), which was the tell. Fixed by diffing the main checkout, applying that diff to
the worktree with `git apply`, reverting the main checkout to clean (`git checkout --`, confirmed via
`git status` both directions), and re-running the entire ladder from scratch against the worktree with
the real changes in place. No other uncommitted work existed in the main checkout at the time (the
diff before revert matched exactly the five files this slice intended to touch, nothing extra), so
nothing was at risk of being clobbered — but every result reported above is from the *second*, correct
run, not the first.

**`Devtools/Design.tsx`'s real-browser check** ran via a one-off Playwright script inside the
container against `/devtools/design` behind `EnsureDevtoolsAccess`, with `DEVTOOLS_PASSWORD` set to a
known value through this worktree's gitignored `compose.override.yaml` (never reading the existing
`.env` value, per the secrets policy) and the app container recreated to pick it up. The
browser-review skill's Alpine/ARM64 Chromium install (`apk add chromium ...`) hit a transient I/O
error mid-extraction on the first attempt — plausible under this worktree's known multi-sibling
Docker-daemon contention (`S7`/`S8` running concurrently) rather than a real disk-space issue (5.8G
free at the time) — and succeeded cleanly on retry, confirming the setup script's own idempotency
claim. With `data-theme` toggled to unset/`dark`/`light` in turn and the page reloaded fresh each
time: `--color-background` resolved to `#0b1017` (unset — the app's dark default) / `#0b1017`
(`dark`) / `#f1f5f8` (`light`) exactly as expected, zero `console`/`pageerror` events on any of the
three, and the page's own chips read `232 tokens live`, `contrast 21/94`, `surfaces 9/9` identically
across all three (the audit necessarily only scores whichever ground is live at render time, same as
every other themed page in the app — there is no dual-ground side-by-side preview on this page, and
none was expected, matching how S11 itself verified grounds by reload rather than a live toggle).

## Open questions

None blocking. `LegacyCard` vs shadcn `Card` staying as two coexisting card systems across the app is
noted above as a routine call, not deferred as an open question — no ledger entry or prior slice
called for consolidating them, and doing so here would have been unrequested scope on files this
slice didn't otherwise need to touch.
