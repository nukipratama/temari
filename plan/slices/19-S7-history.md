# S7 — History

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `B2` · **Status** merged ([#675](https://github.com/nukipratama/temari/pull/675), squashed as `74a9cb9b`)

## Goal

Port `pages/History.tsx`, `Activities/{Feed,Calendar}.tsx`, `components/history/` (6 files).

**Ledger ruling (final)**: feed filters are **cut** — remove `HistoryFilter.tsx`/`HistoryTabs.tsx`,
`useFeedFilters.ts`, and their backend `FeedFilters.php`/`FeedQuery.php` call sites. Journey strip is
**cut** — remove `components/activities/JourneyStrip.tsx`. This narrows the slice's scope relative to
the original plan: no design work needed for either, just their removal alongside the port.

## What actually landed

**The cut reached further than the four named files, mechanically.** `ActiveFilterChips.tsx` and
`ResumeFilterChip.tsx` only ever existed to display/resume the feed filter popover's state; once
`HistoryFilter.tsx` was gone they had no caller left, so they're deleted too. `useLastFilter.ts` (the
"remember the last applied filter" hook) was called only from the deleted `useFeedFilters.ts` — also
orphaned, also deleted. `components/activities/SummaryCard.tsx` (the weekly-recap wrapper) had exactly
one caller, `WeekSection.tsx`, which needed a full restyle anyway — folded into a new shared
[RecapCard](../../resources/js/components/history/RecapCard.tsx) instead of kept as-is (see below). The
Calendar page's own client-side mood-dim filter rode the *same* `HistoryFilter.tsx` popover as the
Feed's server-side one and has no separate ledger entry — cut for the same mechanical reason
(`useCalendar.ts` no longer exposes `moodFilter`/`toggleMood`/`resetFilter`/`isFilteredOut`). Backend:
`FeedFilters.php`/`FeedQuery.php`/`FeedFilterRequest.php` were narrowed to just `range`/`rangeAutoWidened`/
`rangeStart`/`week` — the mood/distance/rarity/sort consts, DTO fields, query clauses and request
accessors are gone; `HistoryController::buildJourneyMatch()` and the `journeyMatch` prop went with the
journey strip. `lib/variants.ts`'s `filterOptionVariants` (a `cva` helper documented as
"shared... in HistoryFilter.tsx") lost its only caller and was removed too.

**`groupByWeek`/`WeekBucket`/`RunWithDetail` — real, filter-independent bucketing logic — moved out of
the deleted `useFeedFilters.ts`** into their own module,
[weekBuckets.ts](../../resources/js/pages/Activities/weekBuckets.ts), rather than being deleted with
the rest of the file. `InlineNote.tsx`'s `RangeWidenedNote` kept its own tiny local `RANGE_LABELS` map
instead of importing from the deleted file.

**Range stayed real windowing, not a cut filter.** The `?range=` chip picker UI is gone (it lived in
the deleted `HistoryFilter.tsx`), but the underlying auto-widen-to-reach-the-newest-run behavior and
the `MAX_RUNS` truncation cap are unchanged and still real — they're not something a user picks, they're
the server keeping the page from either hunting the user for a wider window or shipping unbounded
history. Likewise the `?week=` deep link from the weekly-recap Telegram notification
(`AnalysisMessagePresenter::route('history', ['week' => ...])`) is not a filter — it's an external,
server-driven link into one week — and was kept exactly as before, backend and frontend (`WeekFocusNote`).

**A new shared `RecapCard` component replaces two separate ad-hoc wrappers**
([resources/js/components/history/RecapCard.tsx](../../resources/js/components/history/RecapCard.tsx)),
matching the frozen prototype's own structure exactly: the prototype's `HistoryScreen.tsx` defines one
`RecapCard({ mood, line, chips, size })` function used both by `WeekSection` (feed) and `CalendarView`
(month). The shipped app previously had two independent implementations —
`components/activities/SummaryCard.tsx` for the weekly recap and a local `MonthlyRecapCard` function
inside `Calendar.tsx` for the monthly one — with divergent chrome (a "Temari's Notes" labeled box vs a
"Temari's notes · {month}" labeled box). `RecapCard` is real, not decorative: mood drives `Temari`'s
pose (which already renders its own mood-colored halo ring per pose, so no separate "ring" prop was
needed to match the prototype's `FaceIcon` ring), narration renders through the unchanged
`AnalysisStatus`, and a `notification` prop wires the real `SendNotificationButton` (channel-neutral
Telegram/web-push send) — the prototype's decorative `BellPlus` icon maps onto this existing real
feature rather than being invented fresh. `fallback` is optional: the monthly kind has **no**
rule-based fallback (confirmed in [docs/features/recaps.md](../../docs/features/recaps.md)) so
`Calendar.tsx` omits it; `awaitingScheduleLabel` is a
new pass-through prop so the month's "This month's recap isn't ready yet." copy (previously hardcoded
in the deleted `MonthlyRecapCard`) survives the consolidation.

**`RunListRow.tsx` was unified into one row style, matching the prototype's compact `RunRow` exactly**
— it was safe to restyle freely because, once the Feed's ranked-sort view is cut (sort was a filter
axis), its only remaining consumer is `WeekSection.tsx`. The old component branched into two very
different layouts: a large 64px Temari-mascot-leading plain row, and a wider Kartu-mini-preview-leading
row (with an ignition-ring flash animation) for a run that earned a card. The new row drops both in
favor of the prototype's single dense structure: a small mood-color dot, bold name + distance, a
`RARITY_INK`-colored sparkle icon (`mdi:sparkle-outline`, a new `Icon.tsx` mapping, mirroring how S11
added new icon keys) when the run earned a Kartu, then time·pace·HR and an italic note line. This is a
**deliberate scope trim**, recorded here rather than silent: the big mascot avatar and the full
KartuMini-with-ignition-flash reveal are gone from this one dense list view. Neither is a lost feature
overall — Kartu's own dedicated design pass already happened in `F5`, and the full reveal experience
lives wherever a card is actually earned/viewed, not in a scanning list. Per-run TRIMP was also dropped
from the row (the prototype's own `Run` interface carries no TRIMP field; week-level TRIMP is still
shown in `WeekSection`'s header and `RecapCard`'s chips).

**Feed adopts the prototype's "only the recent weeks show" structure as new, bounded client-side
scope** — a `useState` reveal (`VISIBLE_WEEKS = 2`, "Load older weeks" button) over `buckets`
that are already fully fetched server-side; no new request, no new prop. This matches the program's
explicit allowance to adopt prototype structure when it's "a reasonable client-side addition over data
that already exists." Feed's ranked (non-chronological) sort view (`RankedList`) is gone along with the
sort filter axis it depended on — the page is always week-grouped now, matching the prototype, which
has no sort concept either.

**Calendar's month meta line (`N runs · X km · Y TRIMP`) is a new derived value, also client-side over
existing data** — `useCalendar.ts` gained `monthTotalsOf(weeks)`, summing each week's already-computed
`totalKm`/`runCount` plus a new `totalTrimp` field added to `WeekRow`/`chunkIntoWeeks` (mirroring
`weekBuckets.ts`'s null-preserving "unscored, not zero" convention from
[docs/decisions/unscored-load-is-null-not-zero.md](../../docs/decisions/unscored-load-is-null-not-zero.md)).
No new backend prop.

**Calendar's per-week tap-to-expand narration (the prototype's `WeekRow` with a chevron reveal) was
NOT ported.** This is the one prototype structural element in this slice's scope that was deliberately
left out, and it's a routine implementation-correctness call rather than a fork: porting it would mean
wiring `WeeklySnapshot`+`recap_analysis` into `calendarProps()` as a **new** prop the Calendar page
doesn't have today, which the program's own guidance treats as new backend/API surface rather than a
restyle-in-place. The calendar's real narration surface stays at the month grain (`RecapCard` via
`monthlyRecap`), unchanged in substance from before this slice, just restyled.

**Design-token registry (`grounds.json`) fallout from deleting/restyling files that painted translucent
panels** — `ActiveFilterChips.tsx`/`HistoryFilter.tsx` (`bg-sky/[0.06]`),
`lib/variants.ts`'s deleted `filterOptionVariants` (`bg-sky/10`), the old `WeekSection.tsx`
(`bg-cream-deep/{10,20,60}`) and the old `RunListRow.tsx` (`bg-accent/60`) were the sole painters of
several registered panel specs. `resources/brand/grounds.json`'s `panel` block was hand-edited to drop
the now-stale entries (whole specs where nothing paints them anymore; individual file entries under
`sky/0.06` and `sky/0.1` where other real callers still exist) per
`DesignTokenContrastTest`'s own "drop these" failure message — no generator script exists for this file
despite `plan/README.md`'s R4 note describing one; it's hand-maintained against the failing test's
guidance. Two tests with fixture data pinned to the exact removed specs
(`pages/Devtools/Design.test.tsx`'s `contrast 9/9` chip, `lib/designTokens.test.ts`'s
`cream-deep/0.6`-keyed compositing example) were updated to the new real count (`7/7`) and a still-live
spec (`cream-deep/0.7`) respectively.

## Files touched

New: `resources/js/pages/Activities/weekBuckets.ts` (+test),
`resources/js/components/history/HistoryNav.tsx` (+test, the Feed⇄Calendar pill switcher replacing
`HistoryTabs.tsx`), `resources/js/components/history/RecapCard.tsx` (+test).

Deleted (+ their tests): `resources/js/pages/Activities/useFeedFilters.ts`,
`resources/js/components/history/HistoryFilter.tsx`, `HistoryTabs.tsx`, `ActiveFilterChips.tsx`,
`ResumeFilterChip.tsx`, `resources/js/components/activities/JourneyStrip.tsx`,
`resources/js/components/activities/SummaryCard.tsx`, `resources/js/hooks/useLastFilter.ts`.

Modified: `resources/js/pages/Activities/Feed.tsx`, `Calendar.tsx`, `useCalendar.ts`, `runFixture.ts`,
`resources/js/components/history/WeekSection.tsx`, `InlineNote.tsx`,
`resources/js/components/run/RunListRow.tsx`, `resources/js/components/ui/Icon.tsx` (new
`mdi:sparkle-outline` mapping), `resources/js/lib/mood.ts` (new shared `dominantMood` helper,
`MOOD_FILTER_OPTIONS`/`MoodOption` removed), `resources/js/lib/variants.ts` (`filterOptionVariants`
removed), `resources/js/pages/History.tsx` (unchanged logic, prop-shape follow-through only),
`app/Http/Controllers/HistoryController.php`, `app/Http/Requests/FeedFilterRequest.php`,
`app/Services/Run/FeedFilters.php`, `app/Services/Run/FeedQuery.php`,
`resources/brand/grounds.json`, `docs/features/run-history.md`, `docs/features/recaps.md`,
`docs/features/index.md`, `docs/features/onboarding.md`,
`docs/decisions/unscored-load-is-null-not-zero.md` (citation fix only, decision text untouched).

## Blockers

`F4`, `B2`. Both merged.

## Acceptance criteria

- [x] Feed filters (`HistoryFilter.tsx`/`HistoryTabs.tsx`/`useFeedFilters.ts` + backend
      `FeedFilters.php`/`FeedQuery.php` mood/distance/rarity/sort surface) are removed, not restyled.
      Backend files were narrowed (range/week windowing kept, confirmed no other callers before
      touching them), not deleted outright.
- [x] Journey strip (`components/activities/JourneyStrip.tsx` + `HistoryController::buildJourneyMatch`)
      is removed.
- [x] Deleted components' `.test.tsx`/`.test.ts` files are deleted in the same commit as the component.
- [x] History/Feed/Calendar adopt the frozen prototype's visual/structural language (RecapCard,
      HistoryNav pill switcher, compact RunListRow, load-older-weeks reveal, month meta line) while
      keeping every piece of real functionality the pages had (weekly/monthly recap narration + send,
      range auto-widen + truncation notes, week deep link, lifetime stats, day-cell drill-in to run
      detail, mood legend).
- [x] UI chrome stays Title Case; no em-dashes introduced (the em-dash null-value placeholder in
      `RunListRow`'s HR/pace cells is the codebase's existing, allowed null-placeholder convention, not
      new copy).
- [x] 1:1 test convention: every new file has a co-located test; no new `EXEMPT`/`TS_EXEMPT` entries.
- [x] `docs/features/run-history.md` (+ `recaps.md`, `index.md`, `onboarding.md`, the
      unscored-load-is-null-not-zero ADR's citations) amended in this PR; `php
      scripts/check-doc-citations.php` run directly and green.
- [x] `resources/brand/grounds.json` regenerated (hand-edited against the failing
      `DesignTokenContrastTest` guidance — no generator script exists for it) to drop panel entries
      only the deleted/restyled files ever painted.

## Coverage delta

Frontend: 218/218 test files, 2051/2051 tests passing. Coverage: **95.56% statements / 89.39% branches
/ 95.46% functions / 95.97% lines**. No clean pre-slice baseline was captured for this exact branch
point (edits began immediately after `worktree-setup.sh`), but this sits at or above the last full
figures recorded in the progress table (S11: 95.56/89.32/95.40/95.92), despite `S2`/`S5`/`S10` landing
on top of that baseline before this slice branched.

Backend: full suite 3690/3690 passing, 11090 assertions (`bin pest --parallel --no-tia`). Test count
dropped net vs. earlier recorded figures because this slice's cut removed real test coverage for real
removed behavior (mood/distance/rarity/sort filter tests in `FeedQueryTest.php`,
`FeedFilterRequestTest.php` and `HistoryControllerTest.php`; four `journeyMatch` tests) rather than any
coverage regression on surviving code — every remaining/added test still passes, and `phpstan analyse
--debug` reports 0 errors.

## Verification notes

`pest --group=structure --no-tia` (38/38 — including `DesignTokenContrastTest`'s translucent-panel
registry check, which required the `grounds.json` edit above), full `bin pest --parallel --no-tia`
(3690/3690), `bin phpstan analyse --debug` (0 errors), `bin pint --test` clean, `bin rector --dry-run`
clean (0 changed files), `npx tsc --noEmit` clean, `npm run build && npm run check:chunks` green
(History isn't one of the four hardcoded-budget routes), `npm run check:palette` clean (459 files
scanned), `npm run test:coverage` clean (above), `php scripts/check-doc-citations.php` run directly and
green.

Two backend-adjacent frontend fixtures were pinned to the exact `grounds.json` entries this slice
removed and needed updating alongside it, not because of a real behavior change:
`pages/Devtools/Design.test.tsx` hardcoded the live translucent-panel count (`9/9` → `7/7`, since 4 of
those 9 rows were `(spec, text)` pairs only the deleted/restyled files ever painted), and
`lib/designTokens.test.ts`'s "scores a panel on what it composites to" test used `cream-deep/0.6` (now
gone) as its worked example — repointed to `cream-deep/0.7` (still registered, same family, same
"paints its own family's contrast when composited onto paper" point), with a `--color-text-2` fixture
value added since that spec's registered text token differs from `0.6`'s.

A full `browser-review` sweep was not run for this slice — per `plan/README.md` §9 that's a per-wave
activity (product-manager/designer templates + a sweep across grounds), not a per-slice one. Confidence
here rests on the frontend test suite's extensive `screen.getByText`/`toHaveClass` assertions against
the actual restyled markup (WeekSection, RecapCard, Calendar, Feed, RunListRow, HistoryNav all have
new/updated co-located tests exercising the real rendered structure) plus a direct line-by-line
transcription of the frozen prototype's `HistoryScreen.tsx` for layout/class values.

## Open questions

None blocking. Two things intentionally deferred, both recorded above rather than silently dropped:
per-week tap-to-expand narration inside the Calendar grid (the prototype has it; porting it needs a new
backend prop, treated as out of this slice's routine-restyle scope), and the Kartu-earning row's full
mini-card-with-ignition-flash preview inside History's list (still real, still shown wherever a card is
actually earned/viewed — just not duplicated into this one dense scanning list, matching the
prototype's own leaner row). Either could be reconsidered by a later slice with a concrete reason to
revisit.
