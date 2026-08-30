# B4 — Plan narration, voice-only

**Wave** 2a · **Slot** worktree-be · **Blockers** `B1` · **Status** in review ([#663](https://github.com/nukipratama/temari/pull/663))

## Goal

Three `AnalysisType` cases (day / week / season) with subject types and `cadence()`, three
narrators, three jobs, `AnalysisSubjectMap` + `AnalysisSubjectAuthorizer` arms, registration in
`NarratorsCoverageTest` / `JobsCoverageTest`; the replan pill mapped onto the existing
`Analysis::cooldownRemaining()`; amend `docs/features/plan-periodizer.md`.

Decision 11: **voice-only**. Rules still own every number. No superseding ADR, only an amendment —
this is not architecturally significant enough for its own decision doc.

## What actually landed

**Day narration is keyed off a synthetic user+day key, not `PlannedSession`'s own id.** That row's
id is not stable across weekly regenerations — `Periodizer::regenerate()` deletes and recreates
every unpinned future-date row — so keying on it would silently orphan a day's narration history
every Monday even when the actual prescribed session never changed. Mirrors `BriefingMascotVoice`'s
own per-user-per-day shape (`PLAN_DAY_VOICE_SUBJECT_TYPE`, `Y-m-d` discriminator). Covers only the
**current week's 7 days**, not the full 12-week horizon — bounding the LLM spend to 7 rows per
regenerate rather than up to 84, with future weeks narrating once they actually become current.
Editing a day (`PlanController::update()` — skip/block/move) re-requests that day's narration
whenever the edited date falls within the current week, so a blurb never keeps describing a session
the athlete just changed.

**Week narration attaches to `PlanAdaptation`, not `WeeklySnapshot`.** The existing `WeeklyRecap`
type already narrates `WeeklySnapshot` retrospectively; `PlanWeekVoice` is prospective instead
(explaining the upcoming week's plan, including any deload), and `PlanAdaptation` — the periodizer's
own decision record — is the correct subject for that question, not a second narrative purpose
stacked onto an existing model. Needed a new `analyses(): MorphMany` relation on both `PlanAdaptation`
and `Season` (neither had one).

**Season narration attaches to `Season`**, relying on `AnalysisService`'s own idempotency (an
unchanged, already-`Done` season stays untouched) rather than a bespoke "did the season actually
change" check — simpler, and the existing machinery already does the right thing.

**Narration dispatches from `Periodizer::regenerate()`'s two callers, never from `Periodizer.php`
itself.** The periodizer's own docblock states "no LLM call anywhere in this feature"; literally
adding narration-dispatch code inside `Periodizer.php` would contradict that. `PlanController::regenerate()`
(manual) and `RegeneratePlanCommand` (the weekly cron) both call the new
[PlanNarrationRequester](../../app/Services/AI/PlanNarrationRequester.php) right after calling the
periodizer, keeping the boundary intact while still tying narration to the exact moment the
underlying facts change.

**The weekly cron now has a real LLM cost, deliberately** — a scope decision made explicitly, not
by default. `RegeneratePlanCommand`'s own docblock used to celebrate being LLM-cost-free (why it's
allowed to run for every user, demo included); this slice keeps the regenerate half exactly as free,
but narrates every **non-demo** user's week on the same cron run (`is_demo === false`), so Plan/Home
have fresh voice waiting without requiring a manual click. Reclassified `plan:regenerate` from
`NON_BILLING` to `BILLING` in `DemoBillingExclusionTest` accordingly. The demo account's own Plan
page instead fills every block rule-based on view (`PlanNarrationRequester::ensureDemoFilled()`),
the same path its manual "Reread" already resolves through — otherwise the public demo would show
perpetually-Pending narration blocks.

**The manual Regenerate button carries a real one-hour rate limit — new scope, not originally in the
button's design.** A full regenerate can dispatch up to 9 narration rows (7 days + week + season),
real LLM cost per click. Discovered mid-implementation: reusing `Analysis::cooldownKey()` for this
doesn't work — every narration row's own completion unconditionally starts its own shorter (15-min)
cooldown in `AnalysisService::markDone()`, so a shared key's longer window gets silently overwritten
within moments by the async job finishing. `PlanNarrationRequester` instead owns a dedicated
`Cooldown` key, started immediately when regenerate is dispatched (not deferred to the narration
jobs' completion, which would leave a queue-latency race where two rapid clicks could both slip
through). The cron starts the same cooldown after its own regenerate (so a manual click right after
Monday's auto-run is still rate-limited) but never checks it — the cron always runs regardless.

**Narrators use the toolbox pattern, matching 100% of existing precedent.** Every sampled narrator
(`BriefingMascotVoice`, `WeeklyRecap`, `PrContext`) fetches its own facts via `AgentToolbox` tools
rather than receiving a pre-built context array, and `NarratorsCoverageTest` is written assuming
that shape. Three new tools (`PlanDayTool`, `PlanWeekTool`, `PlanSeasonTool`) mirror `PersonalRecordTool`'s
shape exactly — bound to their subject at construction, no arguments the model could pass to reach
another user's data. `PlanWeekTool` hands the model the periodizer's own rule-based headline/detail
text rather than raw fields, so the LLM's job is turning an existing verdict into warmer prose, never
re-deriving the reason itself.

**Frontend renders all three blocks in the current (pre-S3/S4) `Plan.tsx`**, via the existing
`AnalysisStatus` component — day narration inline per day-card (current week only), week narration
under the adaptation card, season narration under the phase caption — plus the Regenerate button's
new cooldown state (`useCooldownCountdown`, disabled + counting down while cooling). Matches the
B1–B3 precedent of shipping real, testable UI before the screen slice's redesign; "voice-only" made
this more load-bearing than usual, since the copywriter rubric needs an actual rendered surface to
check the narrated prose against `voice-and-tone.md` — otherwise the whole slice's output would be
unverifiable and inert until S3/S4 land.

## Files touched

New: `app/Services/AI/PlanNarrationRequester.php` (+test), `app/Services/AI/Narrators/PlanDayVoiceNarrator.php`,
`PlanWeekVoiceNarrator.php`, `PlanSeasonVoiceNarrator.php`, `app/Jobs/AI/AnalyzePlanDayVoiceJob.php`,
`AnalyzePlanWeekVoiceJob.php`, `AnalyzePlanSeasonVoiceJob.php`, `app/Services/AI/Agent/Tools/PlanDayTool.php`,
`PlanWeekTool.php`, `PlanSeasonTool.php`.
Modified: `app/Services/AI/AnalysisType.php` (+test), `app/Services/AI/AnalysisSubjectAuthorizer.php` (+test),
`app/Services/AI/AnalysisSubjectMap.php` (+test), `app/Services/AI/BackfillAgeGate.php` (+test),
`app/Services/AI/RuleBased/RuleBasedNarrationFiller.php` (+test), `app/Models/AI/Analysis.php`,
`app/Models/PlanAdaptation.php`, `app/Models/Season.php`, `app/Http/Controllers/PlanController.php`,
`app/Console/Commands/Run/RegeneratePlanCommand.php`, `config/azure_openai.php`,
`tests/Feature/Console/DemoBillingExclusionTest.php`, `tests/Unit/Services/AI/Narrators/NarratorsCoverageTest.php`,
`tests/Unit/Jobs/AI/JobsCoverageTest.php`, `resources/js/types/generated.ts` (regenerated),
`resources/js/pages/Plan.tsx` (+test), `docs/features/plan-periodizer.md`.

## Blockers

`B1`. Last in the strict wave-2a order — this is the final slice before the worktree slot frees up
for wave 2b.

## Acceptance criteria

- [x] `PlanDayVoice`/`PlanWeekVoice`/`PlanSeasonVoice` follow the standard 6-wire narration pattern
      end to end (AnalysisType arms, narrators, jobs, subject map/authorizer, coverage suites).
- [x] Copywriter rubric §5 (voice-only enforcement): every prompt asks the model to *narrate* a fact
      already computed, never to produce or adjust a number itself. `PlanWeekVoiceNarrator`'s prompt
      explicitly hands the model the periodizer's own headline/detail text rather than raw fields to
      re-derive from.
- [x] No em-dashes in any of the three new prompts (`NoEmDashInPromptsTest` — caught and fixed a real
      violation across all three during implementation).
- [x] The replan pill is a genuine rate limit on `POST /plan/regenerate`, not just a rendered
      cooldown pill — 1 hour, started immediately, checked before every manual regenerate.
- [x] `RegeneratePlanCommand`'s demo exclusion is explicit and tested (`DemoBillingExclusionTest`'s
      regex-source-check dataset), and the demo Plan page never shows a perpetually-Pending block.
- [x] `docs/features/plan-periodizer.md` amended with a new "Plan narration" section; the top
      "no LLM call anywhere in this feature" line corrected to scope that claim to the deterministic
      layer only.

## Coverage delta

Backend: full suite 3736/3736 passing (up from 3686 pre-slice, +50 tests) across
`PlanNarrationRequesterTest` (new, 12), `NarratorsCoverageTest` (+9: 3 narrator + 3 tool tests × 3
types), `JobsCoverageTest` (+6: 2 tests × 3 jobs), `AnalysisTypeTest`, `AnalysisSubjectAuthorizerTest`,
`AnalysisSubjectMapTest`, `BackfillAgeGateTest`, `RuleBasedNarrationFillerTest` (dataset/case
additions across all five for the new AnalysisType arms). Frontend: 213/213 test files, 2073/2073
tests passing; global coverage 95.56% statements / 89.31% branches / 95.37% functions / 95.92% lines,
including 4 new `Plan.test.tsx` cases covering narration rendering scope (current week only) and the
Regenerate cooldown state.

## Verification notes

`pest --group=structure --no-tia` (38/38), `bin phpstan analyse --debug` (0 errors — caught a genuine
type mismatch in `PlanNarrationRequester::requestDayNarration()`'s call site and two pre-existing
exhaustive `match(AnalysisType)` expressions in `BackfillAgeGate`/`RuleBasedNarrationFiller` that
needed new arms for the 3 added cases), `bin pint` / `bin rector --dry-run` clean (rector caught the
service needed `final readonly class`), full `bin pest --parallel --no-tia` (3736/3736), `npx tsc
--noEmit` clean, `npm run build && check:chunks` green, full `npm run test` green, `check-raw-palette.mjs`
/ `check-doc-citations.php` / `check-see-references.php` clean.

Two real mid-implementation corrections worth recording since they change what the frozen plan text
implied: (1) "dispatched from `Periodizer::regenerate()` itself" turned out to mean its *callers*,
not literally inside `Periodizer.php`, once its own "no LLM call in this feature" docblock was read
closely; (2) "the replan pill mapped onto `Analysis::cooldownRemaining()`" turned out to need a
dedicated `Cooldown` key rather than the literal method, once the interaction with `markDone()`'s own
unconditional shorter cooldown was traced through. Both were surfaced to the user mid-build rather
than decided silently.

## Open questions

None blocking. Two things intentionally deferred: per-day narration doesn't yet extend past the
current week (a real, load-bearing scope boundary, not an oversight — see "What actually landed"),
and there's no UI affordance yet to manually re-trigger a *specific* day's narration independent of
the others (`allowReanalyze={false}` on day blocks) — the whole-week Regenerate button is the only
manual trigger surface this slice ships. Both are natural candidates for `S4` (Plan screen redesign)
to reconsider once it has the prototype's actual day-card layout to work with.
