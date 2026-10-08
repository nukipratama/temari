## AI narration pipeline

Every narrated block flows: **Narrator → Analyze\*Job → Analysis row → AnalysisType → AnalysisController → UI (AnalysisStatus)**.

Briefing and analysis narration is LLM-backed via Azure OpenAI through openai-php/laravel ([AzureOpenAIClient](../../../../app/Services/AI/AzureOpenAIClient.php), [StructuredChatCaller](../../../../app/Services/AI/StructuredChatCaller.php), narrators under [app/Services/AI/Narrators/](../../../../app/Services/AI/Narrators/)). All narrator output flows through the [Analysis](../../../../app/Models/AI/Analysis.php) row model (status: pending / queued / processing / done / failed).

- **Failure and retry**: a job that exhausts `TRIES` ([AnalyzeBaseJob](../../../../app/Jobs/AI/AnalyzeBaseJob.php)) lands in `failed_jobs`, marks its row `failed`, and the block shows "Try again" via [AnalysisStatus.tsx](../../../../resources/js/components/temari/AnalysisStatus.tsx); Horizon's failed-job tab retries it too.
- **Idempotency**: jobs early-exit on a row already `done` ([AnalyzeRowJob](../../../../app/Jobs/AI/AnalyzeRowJob.php), [AnalyzeGroupJob](../../../../app/Jobs/AI/AnalyzeGroupJob.php)), so racing retries never double-bill.
- **Paused vs failed**: a paused block (`AiEnabled` off, Azure unset, breaker tripped) stays `pending` and the hourly `ai:self-heal` ([SelfHealCommand](../../../../app/Console/Commands/AI/SelfHealCommand.php)) re-kicks it; a `failed` block gets bounded retries (`Analysis::MAX_SELF_HEAL_ATTEMPTS`), then dead-letters to `/devtools/narration`. Kickoffs in [routes/console.php](../../../../routes/console.php) never re-dispatch a failed block. The one exception: when a pause other than the app-wide cost ceiling lifts, an active athlete's block that failed during it gets one fresh attempt ([docs/decisions/failed-during-a-pause-retried-once-on-resume.md](../../../../docs/decisions/failed-during-a-pause-retried-once-on-resume.md)). See [docs/decisions/bounded-self-heal-and-dead-letter.md](../../../../docs/decisions/bounded-self-heal-and-dead-letter.md).
- **Cost ceilings**: past the per-athlete daily ceiling (`azure_openai.daily_cost_ceiling_per_user`), that athlete's `pending` blocks are filled by [RuleBasedNarrationFiller](../../../../app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) and manual triggers are refused ([docs/decisions/cost-ceiling-degrades-to-rule-based.md](../../../../docs/decisions/cost-ceiling-degrades-to-rule-based.md)); past the app-wide total (`azure_openai.daily_cost_ceiling_total`), every athlete degrades the same way, generation pauses and one maintainer alert fires ([docs/decisions/app-wide-ceiling-above-the-per-athlete-one.md](../../../../docs/decisions/app-wide-ceiling-above-the-per-athlete-one.md)). `failed` blocks stay `failed`, and there is no global emergency-mode chip.
- **Unconfigured env**: with `AZURE_OPENAI_URI` / `AZURE_OPENAI_API_KEY` empty, [AnalysisService](../../../../app/Services/AI/AnalysisService.php) skips dispatch and rows stay `pending`.
- **Demo**: [DemoSeedCommand](../../../../app/Console/Commands/DemoSeedCommand.php) fills every row rule-based under `AnalysisService::withoutDispatching()`, and demo "Reread" triggers are served rule-based too, so the demo spends no tokens ([docs/decisions/demo-triggers-served-rule-based.md](../../../../docs/decisions/demo-triggers-served-rule-based.md)). Every billing kickoff or scheduler excludes the demo user (`User::notDemo()`), with no exceptions ([docs/decisions/demo-user-billing-exclusion.md](../../../../docs/decisions/demo-user-billing-exclusion.md)).

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
