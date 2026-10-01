## AI narration pipeline

Every narrated block flows: **Narrator → Analyze\*Job → Analysis row → AnalysisType → AnalysisController → UI (AnalysisStatus)**.
The failure model, idempotency guard, and unconfigured-env fallback are documented in the
always-on guideline ("LLM Integration" in AGENTS.md).

- Before fixing narration text, confirm which producer wrote it: the LLM narrator or
  `RuleBasedNarrationFiller`.
- Put a new narrator instruction inside the prompt's existing structure block (FLOW / pick-one /
  REQUIRED STRUCTURE), never as a new top-level section, where the model ignores it.
- Send narrators no signed numbers: pass a magnitude plus a relation word.
- Verify narrator/prompt changes with a capped local call from the main checkout (worktrees have no
  Azure keys): `tinker --execute` calling `generate()` inside a rolled-back transaction, then check
  `narrator.ai.tool_step` in the log. Close a narration bug only after such a run shows it fixed.

### Adding a new narrated block — all 6 wires

Miss one and it fails loudly: `php artisan` breaks on enum match exhaustiveness (PHPStan), or
the structure / coverage gates fail. **Model the shape on an existing sibling and mirror it** —
per-user-per-day follows `TrendCaption`; per-activity follows `RunInsight*`; per-row-model
follows `WeeklyRecap` / `CardFlavor` / `PlanSeasonVoice`. Let `Name` = StudlyCase, `snake` = snake_case.

1. **Narrator** — `app/Services/AI/Narrators/{Name}Narrator.php`. Inject `StructuredChatCaller`;
   expose `generate(...)` returning the narrated string. Build `$context` from real metrics
   (route any pace through `App\Services\Run\Metrics\PaceCalculator`). No em-dashes in the prompt.
2. **Job** — `app/Jobs/AI/Analyze{Name}Job.php` extending `AnalyzeRowJob` (single row) or
   `AnalyzeGroupJob` (multi-row). Row job: override `generateContent()` to resolve the subject and
   call the narrator (see `AnalyzeTrendCaptionJob`). Group job: override `generateAll()` to resolve
   the subject once and return the per-type payload (see `AnalyzeBriefingJob`).
3. **AnalysisType** — `app/Services/AI/AnalysisType.php`: add `case {Name} = '{snake}';`; if the
   subject is a synthetic user/day/month key (not an Eloquent model) add a `*_SUBJECT_TYPE` const
   and return it from `subjectType()`, otherwise return the model class; add the `jobClass()` arm.
4. **AnalysisSubjectAuthorizer** — add the `authorize()` match arm in
   `app/Services/AI/AnalysisSubjectAuthorizer.php`: user-scoped → `$subjectId === $user->id`;
   model-scoped → `self::userOwns(...)`.
5. **Aggregate suites** — register the narrator in
   `tests/Unit/Services/AI/Narrators/NarratorsCoverageTest.php` and the job in
   `tests/Unit/Jobs/AI/JobsCoverageTest.php`. The structure test exempts these namespaces on the
   basis that these suites cover them.
6. **Frontend** — render the block through `resources/js/components/temari/AnalysisStatus.tsx` on
   the page that shows it, so pending / failed / retry states are handled.

Then run `./vendor/bin/sail composer gate` and fix anything red.

**Not every AI surface is a narrated block.** The scoped per-run Q&A stores its own
`run_questions` rows and dispatches its own job instead of using the Analysis row model —
one run holds many questions, which `(subject, type, discriminator)` cannot key. It still
goes through `StructuredChatCaller` and a bound-at-construction toolbox, so persona,
budget, retries and metering are unchanged. See `docs/decisions/scoped-run-qa-not-an-analysis-row.md`
before reaching for a new `AnalysisType` on anything user-initiated and free-form.
