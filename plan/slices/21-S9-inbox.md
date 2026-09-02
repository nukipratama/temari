# S9 — Inbox

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4` · **Status** merged ([#665](https://github.com/nukipratama/temari/pull/665), squashed as `cd0ecd08`)

## Goal

Port `pages/Inbox.tsx` + `components/inbox/InboxRow.tsx` onto the frozen prototype's
`InboxScreen.tsx` design. `NotificationBell` was already ported and wired into
`TopNav`/`MobileTopBar` by an earlier slice, so it's untouched here.

## What actually landed

**Bucketed sections (new scope, per the grilling decision).** Rows now render grouped into
Today / This Week / Earlier ([inboxBuckets.ts](../../resources/js/components/inbox/inboxBuckets.ts))
instead of one flat chronological list. It's a pure client-side grouping over whatever page of rows
is already loaded, no backend shape change. The "this week" boundary is **Monday-start**, verified
against the backend's own convention rather than assumed:
[Periodizer::regenerate()](../../app/Services/Run/Plan/Periodizer.php#L56) computes
`$today->copy()->startOfWeek(Carbon::MONDAY)`. `created_at` is a true instant (ISO-8601 with
offset), so `bucketOf()` reads it with plain `Date` parsing and deliberately does not reuse
`pace.ts`'s `mondayOf()`, which is built for Strava's naive `start_date_local` values — reusing it
here would have silently misbucketed rows near a DST boundary or a non-UTC test runner.

**Relative/absolute time toggle (new scope).** Tapping a row's timestamp toggles between
`formatRelativeId` ("2h ago") and a new `formatAbsoluteId` ("Aug 25 · 07:12") added to `pace.ts`,
mirroring the file's existing true-instant/naive split rather than reusing the naive
`formatShortDateTimeId`. Per-row local state, `<time datetime>` stays the machine-readable wrapper
so the existing accessibility contract is unchanged.

**Rarity badge reuses two existing design-system primitives instead of inventing new ones.** The
prototype's inline `color-mix` badge became `text-label-micro` (the same micro-label utility the
plain kind-label `Eyebrow` already uses) plus `rarityVariants.flag` from
[variants.ts](../../resources/js/lib/variants.ts) and `RARITY_LABELS` from
[runcard.ts](../../resources/js/lib/runcard.ts). First attempt was a soft `bg-rarity-x/15` tint with
`RARITY_INK` text, matching the row's own icon-tile convention — `DesignTokenContrastTest` caught it
for real: that pair scores under 4.5:1 for all five rarities. `rarityVariants.flag` (solid
`bg-rarity-x` + the dedicated `text-ink-on-rarity` token) was already defined for exactly this case
but unused anywhere in the app; verified it clears AA (4.57:1 to 8.92:1 across the five rarities)
before switching, so this landed as a real reuse rather than a second guess.

**Pagination stays the existing Newer/Older page nav.** The prototype's "load older" reveal button
was evaluated and rejected: `InboxController::pageFor()` deep-links straight to whichever page a
given row sits on (renders only that page), which assumes one page on screen at a time. A cumulative
"load older" would need every page up to the deep-linked one merged client-side first — Inertia 2's
`Inertia::merge()` could technically do it, but nothing else in the app uses that pattern yet, and it
is a materially different pagination shape than what's wired today (decision 5's explicit escape
hatch). Kept the existing `PillLink` Newer/Older nav, unchanged.

**Empty state stays `EmptyPanel`.** The prototype's compact `FaceIcon`-avatar `EmptyInboxCard`
treatment doesn't exist in the shipped app (`FaceIcon` is prototype-only), and `EmptyPanel` is the
one shared empty-state primitive used across every other page. Forking a one-off compact variant for
Inbox alone would fragment that shared pattern for no real gain — its copy already matches the
prototype's near verbatim, so only the surrounding row styling changed.

**Not ported: the prototype's post-run `stats` chips** (distance/pace pills on `post_run` rows).
`InboxItem` doesn't carry that data — the mock prototype items author it inline — and adding it would
mean a new backend payload field, outside the settled scope (grilling decisions 1-5 only authorize
bucketing, the time toggle, and the pagination call). Left for a future slice if wanted.

## Files touched

New: `resources/js/components/inbox/inboxBuckets.ts` (+test).
Modified: `resources/js/pages/Inbox.tsx` (+test: bucket grouping), `resources/js/components/inbox/InboxRow.tsx`
(+test: rarity badge, time toggle), `resources/js/lib/pace.ts` (+test: `formatAbsoluteId`),
`docs/features/notification-inbox.md`. `resources/brand/grounds.json` was touched and reverted (see
Verification notes) — no net diff.

## Blockers

`F4`. None outstanding.

## Acceptance criteria

- [x] Rows bucketed into Today / This Week / Earlier, Monday-start, verified against the backend's
      own week convention rather than assumed.
- [x] Per-row relative↔absolute time toggle, preserving the machine-readable `<time datetime>`.
- [x] Every existing interactive behaviour preserved: read/unread state, unlock replay via
      `AccessoryUnlockModal`, post-run replay via the card-reveal endpoint, deep-link open/read,
      empty state.
- [x] Rarity badge on unlock rows restyled onto real rarity data already carried by `InboxItem`, not
      new functionality.
- [x] Pagination approach recorded with reasoning (kept Newer/Older; see "What actually landed").
- [x] No em-dashes in any new/changed UI copy.
- [x] `docs/features/notification-inbox.md` amended in the same PR (bucketing, time toggle,
      pagination reasoning) and `code_refs` extended for the new file.
- [x] Rarity badge fill/text pair verified against `DesignTokenContrastTest` (R9/design-token
      discipline) rather than assumed to pass.

## Coverage delta

Global frontend line coverage `95.92% → 95.95%` (statements 95.56%→95.6%, branches
89.31%→89.37%, functions 95.37%→95.4%; baseline is B4's final numbers, the last slice to touch
frontend coverage). `components/inbox`: `InboxRow.tsx` 100% stmt/100% fn/100% lines/97.14% branches;
`inboxBuckets.ts` fully covered. `pages/Inbox.tsx` 91.11% stmt/83.33% fn/90.47% lines (a few
untested branches in the replay/deep-link paths that predate this slice). `pace.ts`
`formatAbsoluteId` fully covered.

## Verification notes

`pest --group=structure --no-tia`: 38/38, twice over two real contrast issues, both caught by the
gate rather than shipped:

1. The rarity badge's first cut (`bg-rarity-x/15` + `RARITY_INK` text) tripped
   `DesignTokenContrastTest`'s unregistered-translucent-panel check. Registering it in
   `grounds.json`'s `panel` block (mirroring the existing `citrus/0.15` entry) got past that gate,
   but a second contrast test (`keeps every panel/text pair above AA, or pinned in the ledger`) then
   caught the pair for real: all five rarities scored under 4.5:1. That's a real accessibility bug,
   not ledger-worthy debt, so the fix was switching to `rarityVariants.flag` (solid `bg-rarity-x` +
   `text-ink-on-rarity`) instead — verified by hand (WCAG contrast 4.57:1 to 8.92:1 across the five
   rarities) before re-running, then confirmed by `it('keeps every label on an opaque fill above AA')`
   passing. The `grounds.json` panel entries were reverted along with the tint, back to a clean diff.

Full `bin pest --parallel --no-tia`: 3736/3736, 0 failures (this slice touches no PHP, so the count
is unchanged from the pre-slice baseline). `npx tsc --noEmit`: clean. `npm run build && npm run
check:chunks`: green (entry-chunk guard passed; Login stays well under its 160 kB gz budget,
unaffected by this slice). `npm run test:coverage`: 95.6% statements / 89.37% branches / 95.4%
functions / 95.95% lines — clears the 95% line+function gate (see Coverage delta).
`php scripts/check-doc-citations.php`: clean (run directly since this slice touched `grounds.json`
mid-implementation, even though the final diff reverts it).

This worktree's Docker daemon is memory-constrained (3.8 GiB total, 2 CPUs) and shared with the two
sibling S1/S11 worktree stacks running concurrently. `npm ci`, one `pest --parallel` run, and one
`tsc --noEmit` run each hit a transient interruption under that contention (a couple of genuine OOM
kills, and separately the host machine sleeping mid-run, which silently kills any in-flight `docker
compose exec`) — all were simply retried, and the final numbers above are from clean re-runs.

## Open questions

None blocking. Two things intentionally deferred, both flagged above under "What actually landed":
post-run stat chips (distance/pace) on `post_run` rows, and the cumulative "load older" pagination
pattern, both because they'd need new backend shape beyond this slice's settled scope. Natural
candidates for a later pass if wanted.
