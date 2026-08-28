# S7 — History

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `B2` · **Status** todo

## Goal

Port `pages/History.tsx`, `Activities/{Feed,Calendar}.tsx`, `components/history/` (6 files).

**Ledger ruling (final)**: feed filters are **cut** — remove `HistoryFilter.tsx`/`HistoryTabs.tsx`,
`useFeedFilters.ts`, and their backend `FeedFilters.php`/`FeedQuery.php` call sites. Journey strip is
**cut** — remove `components/activities/JourneyStrip.tsx`. This narrows the slice's scope relative to
the original plan: no design work needed for either, just their removal alongside the port.

## Files touched

`resources/js/pages/History.tsx`, `resources/js/pages/Activities/Feed.tsx`,
`resources/js/pages/Activities/Calendar.tsx`, `resources/js/components/history/` (6 files).

Deleted as part of this slice: `resources/js/pages/Activities/useFeedFilters.ts`,
`resources/js/components/history/HistoryFilter.tsx`,
`resources/js/components/history/HistoryTabs.tsx` (+ tests),
`resources/js/components/activities/JourneyStrip.tsx` (+ test), and their backend call sites in
`app/Services/Run/FeedFilters.php` / `FeedQuery.php` (narrow, don't delete the service files outright
unless nothing else calls them — confirm before deleting).

## Blockers

`F4`, `B2`.

## Acceptance criteria

_To be filled when this slice starts._

## Coverage delta

_To be filled when this slice starts._

## Verification notes

_To be filled when this slice starts. Deleted components need their `.test.tsx` deleted in the same
commit (engineer rubric §2); confirm `FeedFilters.php`/`FeedQuery.php` have no other callers before
narrowing them._

## Open questions

_To be filled when this slice starts._
