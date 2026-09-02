# B3 — Structured session segments

**Wave** 2a · **Slot** worktree-be · **Blockers** wave-1 checkpoint · **Status** merged ([#660](https://github.com/nukipratama/temari/pull/660), squashed as `ab8f33aa`)

## Goal

First in wave 2a's strict order, because it redefines what a plan day *is*. A
`planned_session_segments` table (`planned_session_id`, `order`, `key`, `minutes`, `zone`,
`pace_label`) plus a `SegmentKey` enum (`warmup`/`main`/`interval`/`recovery`/`cooldown`);
`WeekPlanBuilder` emits segments; a retirement path for `DistanceBand`, `PaceBand` and
`DistanceBandKm`.

**Freeze `WeekPlanDay` at the end of this slice** — `S3` and `S4` code against the frozen shape.

Deleting `DistanceBandKm.php` touches `docs/features/plan-periodizer.md`'s 24 `code_refs`; land that
doc edit in the **same commit** as the deletion (the doc-citation job is unconditional and would
otherwise redden every open PR in the epic).

## What actually landed

The plan's literal schema (`planned_session_segments` table, generation-time frozen segments)
didn't survive contact with a genuine architectural question surfaced before implementation: should
segment minutes/pace be frozen at generation (matching `distance_band` today) or computed at render
time (matching `DistanceBandKm`'s existing "stays honest against current fitness" philosophy)? The
user chose render-time — and once chosen, even a *shape-only* table doesn't work, because Interval's
rep **count** is itself pace-dependent (phase-scaled work-budget ÷ current Interval pace). So:

**No `planned_session_segments` table, no migration adding one.** `SegmentKey` stays a plain PHP
enum, never persisted. [SegmentGenerator](../../app/Services/Run/Plan/SegmentGenerator.php) —
the direct successor to `DistanceBandKm` — computes a day's full ordered
[SessionSegment](../../app/Services/Run/Plan/SessionSegment.php) list fresh in
`PlanRenderer::dayPayload()`, the same render-time-only discipline `ReadinessClamp`/
`VolumeRedistributor` already followed. `WeekPlanBuilder` only ever decides `session_type` now —
`distance_band`/`pace_band` are retired everywhere, including a migration (`2026_08_29_132609_...`)
dropping both columns from `planned_sessions`.

Full replacement, not additive (explicit user decision, reversing my own initial recommendation):
`VolumeRedistributor`, `ReadinessClamp`, `PlanAdapter`, and `SeasonGamificationContext` all had real
adaptive-plan logic keyed off the retired enums, not just display — all four were rewritten, not
left alone. `SeasonGamificationContext`'s `distance_band === Long` check turned out to be exactly
equivalent to `session_type === Long` on stored rows (both are always set together at generation
time), so that one was a one-line swap once the equivalence was proven, not a redesign. Two more
consumers surfaced only via PHPStan, missed by the initial retirement-blast-radius audit:
`Periodizer::regenerate()` (wrote the now-dropped columns on every `updateOrCreate`) and
`BuildCardContextAction::qualitySessionPaceMet()` (read `pace_band` for badge/rarity scoring) — both
fixed, the latter by reusing `SegmentGenerator::generate()` for its pace label rather than
duplicating the pace-selection logic.

A design bug was caught and fixed mid-implementation, not shipped: `distance_km` was first
implemented as a sum over all of a day's segments (warmup+main+cooldown), which both (a)
contradicted the segment-generation decision that warmup/cooldown are *additional* to the headline
number, not part of it, and (b) made `distance_km` wrongly depend on the athlete having a VDOT
estimate at all — a real regression from `DistanceBandKm`, which never needed pace. Fixed by making
`distance_km` = `SegmentGenerator::coreKmFor()` directly (pace-independent, always available),
computed separately from the segment list rather than derived from it. `ReadinessClamp::apply()`
gained a `core_km` field to carry the same value through the clamped-today path.

**Frontend**: `resources/js/types/inertia.ts`'s `WeekPlanDay` drops `distance_band`/`pace_band`/
`pace_sec_per_km`, gains `segments: PlanSessionSegment[]`; `distance_km` keeps its name but now
means core-work-only. Per the earlier scope call, a full segments UI (matching the prototype's
`SessionBarGraph`) is **not** this slice — `Plan.tsx`/`WeekPlanWidget.tsx` got the minimum
adaptation to keep compiling and behaving correctly: the "Resize" button (cycled `distance_band`,
now meaningless) is deleted outright rather than left as a dead/no-op control, and pace display now
reads the day's main/interval segment instead of a top-level field. The real segments UI is S4's job.

## Files touched

New: `app/Enums/SegmentKey.php`, `app/Services/Run/Plan/SegmentGenerator.php` (+test),
`app/Services/Run/Plan/SessionSegment.php` (+test), migration
`2026_08_29_132609_drop_distance_band_and_pace_band_from_planned_sessions_table.php`.
Deleted: `app/Enums/DistanceBand.php`, `app/Services/Run/Plan/DistanceBandKm.php` (+test).
Modified: `WeekPlanBuilder`, `PlanRenderer`, `ReadinessClamp`, `VolumeRedistributor`, `PlanAdapter`,
`SeasonService`, `Periodizer`, `PlanController`, `CurrentWeekPlanBuilder`,
`SeasonGamificationContext`, `BuildCardContextAction`, `PlannedSession` model,
`UpdatePlannedSessionRequest`, `PlannedSessionFactory`, `resources/js/types/inertia.ts`,
`resources/js/pages/Plan.tsx`, `resources/js/components/home/WeekPlanWidget.tsx`,
`docs/features/plan-periodizer.md`, and every test file for the above (13 backend, 6 frontend).

## Blockers

Wave-1 checkpoint (decision 18). Must land before `B2`.

## Acceptance criteria

- [x] `session_type` is the only thing `WeekPlanBuilder` decides at generation time; segments are
      100% render-time (`SegmentGenerator`), never stored.
- [x] `distance_km` is always available regardless of VDOT status (pace-independent core figure).
- [x] `ReadinessClamp`/`VolumeRedistributor`/`PlanAdapter`/`SeasonGamificationContext` all operate on
      segments/`session_type`, zero references to the retired enums anywhere in `app/`.
- [x] Full retirement verified via repo-wide grep (`DistanceBand\b|distance_band|pace_band`) — zero
      hits outside the historical migration and the new drop-migration.
- [x] `WeekPlanDay`'s frozen shape ships with a `segments[]` breakdown ready for `S3`/`S4` to build
      real UI against.

## Coverage delta

Backend: `n/a` (backend slice, gated by the PHP suite instead — 3636/3636 passing, up from 3627
pre-slice). Frontend: touched only existing, already-covered files (`Plan.tsx`,
`WeekPlanWidget.tsx`, their tests) — no new frontend source files, so no meaningful delta to record.

## Verification notes

`pest --group=structure --no-tia` (37/37), `bin phpstan analyse --debug` (0 errors — caught the two
missed consumers), `bin pint` / `bin rector` clean, full `bin pest --parallel --no-tia` (3636/3636),
`npx tsc --noEmit` clean, `npm run build && check:chunks` green, `check-raw-palette.mjs` /
`check-doc-citations.php` clean. Coupling: `resources/js/types/inertia.ts` is read by `S3`, `S4`,
`S5`, `S7`, `S11` — see [../README.md](../README.md) §8; all five now build against the frozen
`segments[]` shape, not the retired flat band/pace fields.

## Open questions

None blocking. Two things intentionally deferred, not overlooked:

- Per-segment manual editing (dragging a Tempo day's warmup shorter, changing Interval rep count)
  has no request/API surface yet — `UpdatePlannedSessionRequest` only supports whole-day
  date/session_type/pinned edits. Real UI for this is `S4`'s job; the backend already computes
  everything render-time, so no further backend work should be needed when that UI exists — S4
  would just need a way to persist a per-day *override* on top of the computed default, which
  doesn't exist yet and wasn't designed (out of scope: no editing UI was in this slice's brief).
- The zone mapping (`PaceBand` → `HeartRateZones::KEYS`, e.g. Threshold→Z4, Interval→Z5) is new,
  reasonable, physiologically-standard work with no prior convention in the codebase to match
  against (confirmed via investigation: zero existing ties between the two systems). Worth a
  designer's eye once S3/S4 render it visually, but not blocking — it's internally consistent and
  tested.
