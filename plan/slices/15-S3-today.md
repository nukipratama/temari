# S3 — Today

**Wave** 2b · **Slot** parallel worktree · **Blockers** `F4`, `F5`, `B2`, `B3`, `B4` · **Status** merged ([#669](https://github.com/nukipratama/temari/pull/669), squashed as `1d7a95b9`)

## Goal

Port `pages/Home.tsx` + `components/home/` (5) + `components/dashboard/` (6). The most
heavily-blocked screen slice — it depends on the shell, the art, and all of compliance, segments and
narration. Do not start early; every blocker is load-bearing (session segments and compliance shape
what a plan day renders, narration supplies the copy).

**Ledger ruling**: badge/milestone unlock toasts (`UnlockToast.tsx`) are restyled here if Today is
where they fire — confirm against `S10`, which owns milestone *display* on Profile, before doing
redundant design work on the same unlock flow in two slices.

## What actually landed

**Streak reframe (decided directly with the user, recorded in [plan/README.md](../README.md) §5,
2026-08-30, "Streak feature redesign").** The day-grained current-week count
(`CurrentWeekPlanBuilder::streakDays()`) stays on Today but is no longer called a "streak": the
`streak_days`-driven `Chip` with the `mdi:fire` icon and "{N}-day streak" copy is gone, replaced by a
plain, unstyled readout — `"{N} Credited In A Row"`, `text-label-micro` caption, no icon, no chip —
next to the phase chip in [WeekPlanWidget](../../resources/js/components/home/WeekPlanWidget.tsx).
"Streak" going forward names only the week-grained lifetime metric
(`WeeklySnapshot::consecutiveWeekStreak()`), which moves to a Trends badge-board entry (`S6`); Plan's
`StreakPanel` (`S4`) is removed with no replacement there. This slice touches only the day-grained
number already available to Home — `WeeklySnapshot`, `SeasonStreakSummaryBuilder` and Plan-page code
are untouched, per the amendment's slice boundaries.

**The day-by-day glyphs adopt the frozen prototype's icon-by-intensity vocabulary, decoupled from the
streak framing above.** `TodayScreen.tsx`'s `TYPE_ICON` convention (flame for tempo/interval, feather
for easy/long, bed for rest) replaced the old status-only flame glyph — `DayGlyph` now keys the icon
on `session_type`, not `status`. Two new `Icon.tsx` `ICON_MAP` entries (`mdi:feather` → `Feather`,
`mdi:bed` → `Bed`) support this; no other icon runtime changes.

**Compliance-v2's `Overreached` and `Skip` statuses are now visually distinct, not placeholder
concepts.** Every one of the six `PlannedSessionStatus` values gets its own token-safe color
(`done` → `leaf-ink`, `partial` → `leaf-ink` at reduced opacity, `overreached` → `horizon-ink`,
`missed` → `ember-ink` faded, `skip` → `text-3`) — a deliberate departure from the frozen prototype's
own raw-hex amber (`#d97706`) for `overreached`, which this app's token system has no equivalent for
and which would have violated the `citrus`-reserved-for-PR rule if approximated with that family.
`horizon-ink` was chosen instead because the skill doc already defines `horizon` as the "earned"
accent — a good semantic fit for "ran more than prescribed." Each day cell also carries a `title`
attribute (`"{Status} · {score}%"`, plus `"Ran anyway"` when set) so `compliance_score` and
`ran_anyway` are genuinely inspectable, not just computed and discarded — the prototype's own DayCell
doesn't surface a numeric score either, so this is additive rather than a literal port.

**A rest day someone ran anyway now says so.** `ran_anyway` previously had no visual expression in
`WeekPlanWidget`; the per-day sub-label now reads "Ran Anyway" instead of "Rest" when true (still
colored by the day's real `status`, typically `done`).

**The old per-day "in current streak" highlight ring is removed, not replaced.** It existed only to
visually tie day cells back to the flame-streak chip being removed in the same change; keeping a
"which days count toward an unlabeled thing" highlight with no badge to explain it would have been
confusing, so it goes with the framing it served.

**Today's session card gains Temari's mascot avatar**, posed from `briefing.mood` via the existing
`MOOD_TO_POSE` map (already used by `VerdictHero`/`LastRunCard` on this same page) — closing a real
gap against the frozen prototype's `FaceIcon` + label + voice layout using data the page already had,
no new backend call.

**The "this week" supporting-stats block (KPI 3-up, VitalChips, LastRun/TrainingLoad) is now a
disclosure, open by default.** The frozen prototype collapses this whole block behind a
`Collapsible` by default; porting that literally would have hidden real functionality nothing in the
ledger approved cutting the visibility of. The compromise: a new `resources/js/components/ui/
collapsible.tsx` primitive (a byte-identical, already-portable copy of the prototype's own generic
Base UI wrapper) is wired in with `defaultOpen`, so the block renders exactly as visible as before —
the user gains a genuine collapse affordance (matching the prototype's structural language) without
losing anything by default. `CollapsibleTrigger` is rendered as a `<div>` via Base UI's `render` prop
(the same pattern `badge.tsx` already uses) rather than its default `<button>`, because the trigger's
content (`SectionLabel`) is a `<div>`-rooted component — nesting a div inside a button is invalid HTML.

**The other three `home/` components (`VerdictHero`, `EvidenceList`, `NoVerdictPanel`) and all six
`dashboard/` components are unchanged.** Read against the frozen prototype: the "you vs past you"
block already matches `TodayScreen.tsx`'s own structure closely (heading, headline, evidence rows in
a bordered stack), and F2/F3's mechanical sweep had already brought every one of these six components
onto the semantic token system. The ledger carries no dedicated "restyle" verdict for them (unlike
Kartu, Persona mix, and Badge/milestone, which explicitly earned real design passes) — `dashboard.md`
frames them as "supporting detail," and the prototype's own Today mockup doesn't show equivalents of
`KpiTile`/`VitalChips`/`LastRunCard`/`TrainingLoadCard`/`FeaturedKartuPanel` as separate visual
language at all, only condensed `MiniRow`/`StatFigure` mini-cards inside its collapsed disclosure. A
full pixel-level rebuild of all six into that compact shape was judged out of proportion to what the
ledger and the program's decision 5 ("keep and restyle existing... adopt structural language where
reasonable") actually call for; they're ported structurally (grouped under the new disclosure,
matching the prototype's bundling) rather than visually rewritten. Recorded here as a routine,
transparent implementation-correctness call, not a silent scope cut.

**`UnlockToast.tsx` needed no action.** It's mounted once in `AppShell.tsx` (`F4`'s shell), not in
`Home.tsx` or anywhere under `components/home/`/`components/dashboard/` — this slice's file scope
never touches it. The ledger's "confirm against S10" note is satisfied by this finding: there is no
redundant unlock-toast work happening here to coordinate against.

## Files touched

Modified: `resources/js/pages/Home.tsx` (+test), `resources/js/components/home/WeekPlanWidget.tsx`
(+test), `resources/js/components/home/TodaySession.tsx` (+test), `resources/js/components/ui/
Icon.tsx`, `resources/brand/grounds.json` (dropped one stale `horizon/0.18` call-site entry — R4).
New: `resources/js/components/ui/collapsible.tsx` (+test).

## Blockers

`F4`, `F5`, `B2`, `B3`, `B4`. All merged.

## Acceptance criteria

- [x] The "{N}-day streak" `Chip` + `mdi:fire` icon is gone from `WeekPlanWidget`; the word "streak"
      never appears on Today. The underlying `streak_days` number still renders, as plain text, when
      positive.
- [x] Day-by-day glyphs key their icon shape on `session_type` (flame/feather/bed), independent of
      the streak reframing above and independent of the day's compliance `status`.
- [x] All six `PlannedSessionStatus` values (`planned`/`done`/`partial`/`missed`/`overreached`/`skip`)
      render with visually distinct, token-safe treatment — `overreached` never reads the same as
      `done`, `skip` never reads the same as `missed`.
- [x] `compliance_score` and `ran_anyway` are genuinely surfaced (accessible `title`, and a "Ran
      Anyway" sub-label on rest days), not computed and silently discarded.
- [x] No cuts to existing real functionality beyond the streak reframing itself — the "this week"
      stats block stays visible by default despite adopting the prototype's collapsible structure.
- [x] No em-dashes in any new UI copy. UI chrome stays Title Case (`"Credited In A Row"`, `"Ran
      Anyway"`, `"This week's plan"` unchanged).
- [x] 1:1 test convention: every new/changed component has a co-located test; no new
      `EXEMPT`/`TS_EXEMPT` entries.
- [x] `resources/brand/grounds.json` reconciled (one stale call site dropped after removing the
      streak-highlight translucent panel) — confirmed via `DesignTokenContrastTest`.

## Coverage delta

Backend: unaffected (no PHP touched). Full suite still 3736/3736 passing, 11424 assertions
(`bin pest --parallel --no-tia`) — matches the pre-slice baseline exactly.

Frontend: 217 test files (216 passing, 1 pre-existing unrelated failure — see verification notes) /
2108 tests (2107 passing). Coverage: **95.63% statements / 89.45% branches / 95.44% functions /
95.99% lines**, vs the pre-slice baseline of 95.56% / 89.32% / 95.40% / 95.92% (S11, the last merged
slice on this branch before S3) — statements +0.07pp, branches +0.13pp, functions +0.04pp, lines
+0.07pp. All four metrics improved: the new `collapsible.tsx` primitive and the expanded
`WeekPlanWidget`/`TodaySession`/`Home` test cases are fully exercised, outweighing any untested
fallback branches the changes added (e.g. `STATUS_TONE`'s default-neutral arm for an unmapped
status).

## Verification notes

`pest --group=structure --no-tia` (38/38 — caught the stale `grounds.json` entry from removing the
streak-highlight panel, fixed in the same commit), full `bin pest --parallel --no-tia` (3736/3736,
11424 assertions, matches baseline), `npx tsc --noEmit` clean, `npm run build && npm run
check:chunks` green (Home: 226.6 kB gz against its 240 kB budget, up from the pre-slice baseline but
still comfortably under), `npm run check:palette` clean (456 files scanned, zero off-token
utilities), `php scripts/check-doc-citations.php` clean (run directly per the ladder's rule for any
token/grounds-touching slice).

**Host contention, twice over, documented for whoever runs this ladder next.** `bin pest --parallel`
crashed once with a `WorkerCrashedException` (exit 137, SIGKILL) on an unrelated file while a sibling
`S4` worktree was measured at 184–317% CPU via `docker stats` — genuine OOM under the shared pool, not
a real failure; confirmed and retried per the program's contention guidance. Separately, mid-run, the
host's Docker Desktop was restarted by the user with bumped resource limits (memory ceiling jumped
3.8 GiB → 9.7 GiB), which killed and recreated every worktree's containers out from under any
in-flight command; `sail up -d` (plus one manual `docker start` for a container stuck in `Created`)
recovered the stack, and the full suite was re-run clean from scratch afterward.

**One pre-existing, out-of-scope test failure found, not caused by this slice.**
`resources/js/layouts/AppShell.test.tsx`'s `"CardReveal (the pack) takes priority"` case
(`findByText('Syncing in')`) fails identically on the pre-slice baseline, confirmed by `git stash`
before touching anything and re-running that file in isolation both with and without this slice's
diff applied. `AppShell.tsx`/`UnlockToast.tsx`/`CardReveal` are outside this slice's file scope
(`Home.tsx`, `components/home/`, `components/dashboard/`) — flagged rather than fixed blind, since it
touches shell-level sync/unlock priority logic this slice has no context on and two sibling slices
(`S4`, `S6`) are running in parallel worktrees that could plausibly also touch shell state.

## Open questions

None blocking. The pre-existing `AppShell.test.tsx` failure noted above is worth a follow-up outside
this slice — not filed as a new slice since it's a single test, not a feature gap.
