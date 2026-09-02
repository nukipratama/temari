# PP4 — Demo seed completeness

Make the demo account render every surviving surface, so a browser sweep can actually see the
screens the eleven screen slices built. **P30** is the whole slice: "demo seed must populate every
surviving surface, happy path", with deliberately-reachable empty states explicitly not required.

No frontend work. This slice touches seeders, and nothing under `resources/js/`.

## Why now

`PP4` sat last in the slice map. The 2026-09-01 browser sweep moved it earlier, because five of the
gaps below are surfaces **no screenshot can reach** on the current demo data. Reviewing treatment on
screens whose surfaces are half-invisible means re-reviewing them all afterwards; `PS13` in
particular is a copy pass whose *completeness* cannot be verified against copy that never renders.

## Goal

Six confirmed data gaps, each verified live against the running app on 2026-09-01 rather than
inferred. A seventh (`goal_sec`) was investigated and **reassigned** — see below.

| # | gap | evidence | fix |
|---|---|---|---|
| 1 | **No `unlock` inbox rows**, so `P12`'s unlock surface and `PS9`'s rarity badge — which `PS9` built and could not see — never render. | queried live: 2 notifications (`post_run`, `weekly_recap`), 21 `user_unlocks`, 0 pending jobs | **`demo:seed` does not converge, and neither `PS9`'s recorded cause nor the first hypothesis here was right.** Proved empirically: deleting the 21 `user_unlocks` and re-running `GrantEligibleUnlocksAction` under a sync queue grants 21 and writes **21 inbox rows**. The seeder is correct on a *fresh* database, and `seedOne` never invokes the unlock engine at all — the comment at `DemoRunSeeder.php:154` claiming incremental PR-driven grants is stale. The real defect is **non-convergence on an existing one**: once `user_unlocks` is fully populated, the engine short-circuits at `GrantEligibleUnlocksAction.php:51` (`array_diff(allKeys, already) === 0 → return []`) and notifies nothing, so a database seeded before the `withSyncQueue` fix landed can never gain its inbox rows. `demo:seed`'s documented "idempotent, re-run any time to converge" is **false for this surface**. Fix: a `seedUnlockInboxEntries` step that writes a row for any `user_unlocks` lacking one, following `seedNarrationInboxEntries`' existing direct-write pattern rather than the queued notify path. |
| 2 | **No `training_preferences` row at all** for the demo user. Settings' whole preferences card runs on `TrainingBaseline` fallbacks, `run_days` is empty, and the "which one's the long run?" block (`TrainingPreferencesCard.tsx:271`) is gated on selected days, so it never renders. | `$user->trainingPreference` is `NULL`; live DOM shows 7 day cells, none selected, and no long-run block at either viewport | Seed a representative row: experience, sessions/week, goal type, `run_days`, `long_run_day`. `DemoRunSeeder.php:446` already *reads* the row (`$preference?->run_days`, `?->long_run_day`) for plan generation, so seeding one also makes the seeded plan honest rather than fallback-derived. |
| 3 | **No run carries `max_grade_pct`.** `VitalsCard.tsx:102-120` gates its steepest-grade and flat-pace tiles on `max_grade_pct >= 3`, so the vitals card ships one tile in a row built for three. | 0 of 127 `activity_details` have `max_grade_pct` in `stream_summary`; max is `NULL` | The demo `StreamSynthesizer` emits no altitude series, so `StreamAnalysis.php:247` never computes a grade. Give at least one blueprint real elevation. |
| 4 | **No run questions.** `AskAboutRun.tsx:109-121`'s prior-questions list is gated on `questions.length > 0`, so the Q&A panel only ever shows its empty state. | `run_questions` is empty (0 rows) | Seed a couple of answered questions on the sampled run. Note these are **not** `Analysis` rows — scoped run Q&A has its own table by decision (`docs/decisions/scoped-run-qa-not-an-analysis-row.md`), so the existing `withoutDispatching` guard does not cover them and they must be written directly rather than dispatched. |
| 5 | **Inbox has nothing older**, so `Inbox.tsx:144-164`'s load-older control — which `PS9` built as a real `?shown=` growing window under P3 — never renders. | live DOM at mobile: no load-older control | Largely falls out of #1: 21 unlock rows backdated across the season give the `earlier` bucket real content and push the window past its first page. Verify rather than assume. |
| 6 | **Onboarding is unreachable.** `DemoRunSeeder.php:645-650` heals the demo user to onboarded on every re-seed, on purpose — a fully-populated showcase account should never land in the wizard. | `ensureDemoUser` calls `markOnboarded()` unconditionally when `onboarded_at` is null | **Structural exception to P30, not a gap to close.** Do not add a second `is_demo` user to work around it: `is_demo` gates billing-scheduler exclusion (see `docs/decisions/demo-user-billing-exclusion.md`), and a second flagged account changes that surface for no product reason. Document the local one-liner to reach the wizard instead, and record that a browser sweep can never cover Onboarding. |

### Reassigned out of this slice

**`goal_sec` is not a seed gap.** `ProgressionCard.tsx:95` gates the prototype's `goal: sub-50:00`
chip on `series.goal_sec != null`, and `ProfileController.php:149` passes
`fn (PersonalRecord $pr): ?int => null` — hardcoded. The chip **can never render, for any user, on
any data**. No amount of seeding reaches it. That is a dead branch in the app, and wiring it means
choosing a goal source (race goal? season goal? a PR target?), which is a product call rather than a
fixture one. Moved to `PS14`, flagged as needing a decision before it is either wired or cut.

## Files touched

- `database/seeders/Demo/DemoRunSeeder.php` — the `withSyncQueue` scope for #1, the preferences row
  for #2, the run-questions rows for #4.
- `database/seeders/Demo/BlueprintLibrary.php` and/or `StreamSynthesizer.php` — elevation for #3.
- Their 1:1 tests.
- `plan/parity/README.md` §5 — mark merged.

Deliberately **not** touched: anything under `resources/js/`, `app/Http/`, or `app/Services/`. If a
gap appears to need an app change, that is a signal it is not a seed gap — as #7 turned out to be.

## Blockers

None. All eleven screen slices are merged; this is the last thing between the port and a re-sweep
that can actually see every surface.

## Acceptance criteria

1. The demo inbox holds rows of **at least four** `NotificationKind`s including `unlock`, and the
   unlock rows carry a resolvable `unlock_key` so `PS9`'s rarity badge renders.
2. `$user->trainingPreference` is non-null with a non-empty `run_days` and a set `long_run_day`, and
   Settings renders the "which one's the long run?" block.
3. At least one seeded run has `max_grade_pct >= 3`, and its vitals card renders all three tiles.
4. At least one seeded run has answered `run_questions`, and its Q&A panel renders the prior list.
5. Inbox's load-older control renders and pages.
6. `demo:seed` stays **idempotent** — re-running converges rather than duplicating. This is the one
   that bites: every fix here writes rows, and `InboxNotification::record`'s dedupe key is the only
   thing standing between #1 and 21 new rows per re-seed.
7. No LLM tokens spent. The `withoutDispatching` guard still wraps everything, and run questions are
   written directly rather than dispatched (see #4).
8. `./vendor/bin/sail composer check` green.

## Coverage delta

Record before/after. Seeder changes usually move coverage very little, but `DemoSeedCommandTest` is
the single most expensive test in the suite (it was 52% of serial runtime before consolidation), so
**do not add a fresh `demo:seed` call per assertion** — extend the existing seed-once-assert-many
fixture.

## Verification notes

- Run the fast-feedback ladder, not the full gate first.
- Verify each acceptance criterion against the **running app**, not the seeder's own log line. The
  seeder printed `0 accessory unlocks granted (all already unlocked)` on a database with zero unlock
  inbox rows — an honest log line about the wrong thing, which is exactly how this gap survived
  `PS9`.
- **Test convergence on a populated database, not a fresh one.** Every gap here reproduces only on a
  database that has already been seeded; `migrate:fresh` then `demo:seed` hides all of them. Gap #1
  was mis-diagnosed twice before an empirical test settled it, both times by reasoning about the
  code path instead of running it.
- The cheapest full check is a re-sweep once this lands: `npm run build`, `demo:seed`, then
  `shoot.mjs` + `audit.mjs`. Both sweep bugs found on 2026-09-01 are fixed (`28a15249`, `6bed9eb9`),
  so activity detail and a full-height Login are now actually captured.

## Open questions

1. **How much elevation is honest for #3?** The demo dataset is a flat-city profile by design
   (`DemoLocation`). Giving one blueprint a hill is enough to light the tiles, but a hill in a
   dataset that is otherwise sea-level flat may read as a data bug to anyone looking closely.
2. **Should #5 be verified against the real page size, or just "more than one page"?** `PS9` built
   the window as `?shown=` growing rather than paged, so "older exists" and "the control renders"
   are the same assertion; there is no second page to check.
