# W7 — the LLM trigger map

The last slice of the program, and deliberately last so it documents the final shape. Produces
`docs/architecture/llm-triggers.md`: one document answering **"what makes this app call an LLM, and
when"**. Requested by the user 2026-09-02. The original brief is
[../../slices/33-W7-llm-trigger-map.md](../../slices/33-W7-llm-trigger-map.md); this supersedes it
with what the audit actually found.

**Docs-only.** No behaviour changes. The brief's rule stands: a trigger found firing for a surface
that no longer exists is recorded as a finding, not fixed inline. Wrong *comments* are a different
matter — this slice is documentation, and a scheduler comment that denies its own billing is the
exact thing the document exists to prevent.

## What the audit found that the brief did not predict

**A weekly scheduled command bills, and its own comment says it does not.**

`routes/console.php:63` describes `plan:regenerate` (Mondays 00:07) as *"No LLM involved
(deterministic periodizer)"*. The periodizer is indeed free. The command is not:
`RegeneratePlanCommand.php:41` then calls `PlanNarrationRequester::requestForCurrentWeek()` for
every non-demo user, which dispatches up to **nine** rows per user per week —
`PlanDayVoice` ×7 and `PlanWeekVoice`, **both with `invalidate: true`**, so they are re-billed every
Monday by design, plus an idempotent `PlanSeasonVoice`.

The re-billing itself is deliberate and documented on the method (*"the periodizer just rewrote what
they'd describe"*). **The scheduler comment is what is wrong**, and it is wrong in the direction that
matters: someone auditing spend by reading `routes/console.php` would skip this line. This is the
same shape as `W2`'s featured-kartu finding — narration billing behind a description that says it
does not.

**A second stale comment, describing a retired architecture.** `routes/console.php:33-36` says the
daily kickoff dispatches *"headline, suggestion, mascot voice, featured card voice, greeting + trend
caption"*. `AnalysisService::requestBriefing()` dispatches exactly **one** type,
`BriefingMascotVoice`. `daily_greeting_user_day`, `trend_caption_user_day` and `persona_summary_user`
are listed in `UserEraser.php:51-55` as retired types whose enum cases are gone; the featured-card
voice went with `W2`.

**Verified, not taken on trust.** The audit's one unchecked claim was `plan:score-compliance`
(00:03), whose "no LLM" comment it could not confirm. Checked here: `ScoreComplianceCommand` imports
no AI class. The comment is accurate.

## What the document covers

**Four trigger origins**, the spine of the map:

| origin | mechanism | what it fires |
|---|---|---|
| Scheduled | `routes/console.php` | `ai:daily-briefing` 00:01, `ai:weekly-recap` Mon 00:01, `ai:weekly-profile` Mon 00:05, **`plan:regenerate` Mon 00:07**, `ai:monthly-recap` 1st 05:45, `ai:trend-read` (30d daily 06:00, 90d every 3rd day, 12mo weekly), `ai:self-heal` hourly |
| Ingest cascade | `DispatchPostRunAnalysis`, queued on `ActivityIngested` | `PrContext` per beaten PR, `CardFlavor`, the `PostRunSpeech`+`RunInsight` group, `BriefingMascotVoice`, `ProfileVoice`, plus deferred `WeeklyRecap` / `MonthlyRecap` rows staged Pending |
| User-initiated | `AnalysisController::trigger`, `RunQuestionController::store`, `PlanController::regenerate` | any narrated block, the scoped run Q&A, and a whole week of plan narration |
| Recovery | `ai:self-heal`, `ai:recover`, the per-user `/ai-usage` re-arm | re-dispatches stalled rows, always `invalidate: false` |

**Twelve `AnalysisType` cases**, each with subject type, discriminator rule, cadence and job class.
Every case is dispatched from at least one path — **none is orphaned**, which is worth stating
explicitly given `W2` found one that was.

**Seven things that stop or limit a call**, and they do not behave alike:

1. `AiEnabled` off, 2. Azure unconfigured, 3. the config circuit breaker — **all three rest the row
   at `Pending`**, cost nothing, and `ai:self-heal` resumes them for free.
4. **The daily cost ceiling is the odd one out.** A `pending` row is filled from
   `RuleBasedNarrationFiller` and marked `Done`; a `Failed` row is explicitly left alone so it keeps
   its dead-letter visibility. Self-heal cannot resume it — it clears on the clock, not on a switch.
   And a *manual* trigger past the ceiling gets a 409 rather than a rule-based fill, because
   `generationPaused()` asks with `withBudget: true`.
5. **Demo exclusion** — `notDemo()` on the five AI kickoff commands and every `SelfHealer` sweep; a
   demo user's manual trigger is served rule-based before any pause check.
6. **The backfill age gate** (84 days) — the only one that gates *both* automatic dispatch and
   manual triggers, and it is exhaustive per type: chained and recap types are exempt.
7. **Cooldown and the idempotency guard are two different defences.** The 900s cooldown stops a
   human clicking twice, at the controller, before a job exists. The `status === Done` check inside
   the job handler stops a UI trigger and a Horizon retry racing into a double bill. Plan narration
   has a *third*, separate 3600s cooldown in `PlanNarrationRequester`.

**The one path that is not an `Analysis` row**: the scoped run Q&A persists `RunQuestion` rows and
settles them in `AnswerRunQuestionJob`, while still funnelling through `StructuredChatCaller`.

## Cost: a query, not a table

**Ruled before the slice started.** The map carries the shape of each trigger plus a reproducible
query, not a snapshot of numbers.

`ai_token_usages` on the `analytics` connection was inspected directly: 15 columns (`kind`, the four
token counters, `reasoning_tokens`, `steps`, `model`, `latency_ms`, `truncated`, the deleted-user
snapshot pair) and a compound `(created_at, kind)` index, so a windowed `GROUP BY kind` is
index-backed. **The query in the doc was executed before it was written down** — it runs clean and
returns zero rows locally, because the demo seed spends no tokens.

The limitation is stated rather than papered over: **`kind` is the narrator, not the origin.** A
`run_insight` row cannot say whether it came from the ingest cascade, a "Reread", or the hourly
self-heal. Making origin queryable needs an origin column plus a write-path change; that was offered
and declined with the epic ready to merge, and the note records where that work would start.

## Files touched

`docs/architecture/llm-triggers.md` (new), linked from `docs/architecture/index.md` and the
`ai-pipeline` note. Two stale comments in `routes/console.php`. No app code.

Also carries `W5`'s tracker row.

## Acceptance criteria

1. Every `ai:*` entry in `routes/console.php` is accounted for, derived from that file rather than
   from memory — a scheduled command with no row in the map is a bug in the map.
2. All twelve `AnalysisType` cases appear with origin, cadence and key; the Q&A path is included
   with its reason for not being an `Analysis` row.
3. Each of the seven stops is stated with its actual behaviour, not just named, and the four that
   look alike are distinguished.
4. Every claim cites `path:line`, so `check-doc-citations.php` keeps it honest.
5. The spend query is runnable and has been run.
6. The two stale `routes/console.php` comments are corrected.

## Verification notes

_To be filled as the slice runs._

## Open questions

_None. The cost question was ruled before the slice started._
