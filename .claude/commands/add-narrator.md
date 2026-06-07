---
description: Scaffold a new AI narrator end-to-end (narrator + job + AnalysisType wiring + tests) so no wire is missed.
argument-hint: <NarratorName> (e.g. WeeklyHighlight)
---

Scaffold a new narrated AI block for `$ARGUMENTS`. Follow every step — a missing
wire breaks `php artisan` (enum match exhaustiveness via PHPStan) or the
structure / coverage gates. Model the shape on an existing sibling: a per-user
per-day block follows `TrendCaption`; a per-activity block follows
`RunInsight*`; a per-row model block follows `WeeklyRecap` / `PrContext` /
`CardFlavor`. Read that sibling first and mirror it.

Let `Name` = `$ARGUMENTS` (StudlyCase), `snake` = its snake_case value.

1. **Narrator** — `app/Services/AI/Narrators/{Name}Narrator.php`. Inject
   `StructuredChatCaller`; expose `generate(...)` returning the narrated string.
   Build the `$context` array from real metrics (route any pace through
   `App\Services\Run\Metrics\PaceCalculator`). No em-dashes in the prompt.

2. **Job** — `app/Jobs/AI/Analyze{Name}Job.php` extending `AnalyzeRowJob`
   (single row) or `AnalyzeGroupJob` (multi-row). For a row job, override
   `generateContent()` to resolve the subject and call the narrator (see
   `AnalyzeTrendCaptionJob`). For a group job, override `generateAll()` to
   resolve the subject once and return the per-type payload (see
   `AnalyzeBriefingJob`).

3. **AnalysisType** — `app/Services/AI/AnalysisType.php`:
   - add `case {Name} = '{snake}';`
   - if the subject is a synthetic user/day/month key (not an Eloquent model),
     add a `*_SUBJECT_TYPE` const and return it from `subjectType()`; otherwise
     return the model class.
   - add the arm to `jobClass()` → `Analyze{Name}Job::class`.

4. **AnalysisController** — add the `authorizeSubject()` match arm
   (`app/Http/Controllers/Api/AnalysisController.php`): user-scoped →
   `$subjectId === $user->id`; model-scoped → `$this->userOwns(...)`.

5. **Register in the aggregate suites** — add the narrator to
   `tests/Unit/Services/AI/Narrators/NarratorsCoverageTest.php` and the job to
   `tests/Unit/Jobs/AI/JobsCoverageTest.php` (these are how the family stays
   covered; the structure test exempts these namespaces on that basis).

6. **Frontend** — render the block through
   `resources/js/components/temari/AnalysisStatus.tsx` on the page that shows it,
   so pending/failed/retry states are handled.

After scaffolding, run `./vendor/bin/sail composer check` and fix anything red.
Do not commit unless asked.
