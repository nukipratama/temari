# W7 — the LLM trigger map

**Wave** 3 · **Slot** main checkout · **Blockers** none (runs last, so it documents the final shape) · **Status** todo

## Goal

Produce **one document that answers "what makes this app call an LLM, and when"** — every trigger,
its cadence, what gates it, and what it costs. Requested by the user 2026-09-02: *"so we can assess
llm on what part and i can actually have understanding on all llm triggers."*

The deliverable is `docs/architecture/llm-triggers.md`, a curated note in the knowledge base. **Not a
code slice** — no behaviour changes. If the audit finds a trigger that is wrong, over-firing or
unreachable, that is a finding for its own slice, not something to fix inline.

## Why this does not exist yet

The pieces are all documented and none of them answer the question:

- [ai-pipeline](../../docs/features/ai-pipeline.md) explains the Narrator → Job → `Analysis` row
  machinery, not who starts it.
- [ai-narration-internals](../../docs/architecture/ai-narration-internals.md) explains prompt
  construction and the demo fill path.
- `AnalysisType::cadence()` names a cadence per type, but cadence is not the trigger: `OnDemand`
  covers both a scheduled command and a user button, and `PerActivity` fires from an ingest cascade
  the enum does not mention.
- The scheduled half lives in `routes/console.php`, the reactive half in a queued listener, the
  user half in two controllers, and the recovery half in `SelfHealer`. **Nothing joins them up.**

`W2` is why this is worth doing now rather than earlier: it found
`briefing_featured_kartu_voice` billing every morning for a panel deleted three waves prior, and
nothing in the repo would have surfaced that. A trigger map is the artifact that would have.

## What to cover

Every path that can reach `StructuredChatCaller`, which is the one chokepoint — 14 narrators plus
`NarratorContinuity` and `TemariPersona` sit behind it.

**The four trigger origins**, which is the spine of the document:

| origin | mechanism | examples |
|---|---|---|
| **Scheduled** | `routes/console.php` | `ai:daily-briefing` 00:01, `ai:weekly-recap` Mon 00:01, `ai:weekly-profile` Mon 00:05, `ai:monthly-recap` 1st 05:45, `ai:trend-read` (30d daily 06:00, 90d every 3rd day, 12mo weekly) |
| **Ingest cascade** | `DispatchPostRunAnalysis`, a queued listener on a completed Strava ingest | `post_run_speech` → `run_insight`, plus `card_flavor`, `pr_context`, and the week/month/profile rows it originates |
| **User-initiated** | `AnalysisController::trigger` (the per-block "Reread"), `RunQuestionController::store` (scoped run Q&A) | any narrated block; the Q&A is the one that is **not** an `Analysis` row — see [scoped-run-qa-not-an-analysis-row](../../docs/decisions/scoped-run-qa-not-an-analysis-row.md) |
| **Recovery** | `ai:self-heal` hourly, plus the `/ai-usage` dead-letter re-arm | re-dispatches stalled rows; bounded by `Analysis::MAX_SELF_HEAL_ATTEMPTS` |

**Per trigger, state**: what fires it, what key (`subject_type` / `subject_id` / `discriminator`)
it writes, whether that key makes it re-bill on change, which narrator and deployment it routes to,
and what suppresses it.

**The five things that stop a call**, each with its behaviour, since they differ in kind:

1. `AiEnabled` off — pauses, row stays `Pending`, `ai:self-heal` re-kicks it later for free.
2. Azure unconfigured — dispatch skipped entirely.
3. The config circuit breaker — same shape as (1).
4. **The daily cost ceiling — the one that does *not* pause.** `pending` blocks are served from
   `RuleBasedNarrationFiller` and marked `done`; `failed` blocks are excluded and stay `failed`.
5. **Demo exclusion** — five `ai:*` commands filter `notDemo()`, and demo triggers are served
   rule-based so the public demo spends nothing.

**Also worth a line each**: the twelve-week backfill cutoff (`BackfillAgeGate`), the per-type
cooldown behind "Reread", and the idempotency guard that stops a UI retry racing a Horizon retry
into a double bill.

## Files touched

`docs/architecture/llm-triggers.md` (new), plus a link from
[docs/architecture/index.md](../../docs/architecture/index.md) and from the `ai-pipeline` note. No
app code.

## Acceptance criteria

1. Every one of the 12 `AnalysisType` cases appears in the map with its origin, cadence and key,
   and the Q&A path is included with its reason for not being an `Analysis` row.
2. Every `ai:*` entry in `routes/console.php` is accounted for, and the map is derived from that
   file rather than from memory — a scheduled command with no row in the map is a bug in the map.
3. Each of the five suppression paths is stated with its actual behaviour, not just named.
4. Every claim cites `path:line`, per the knowledge-base rule, so `check-doc-citations.php` keeps it
   honest as the code moves.
5. Any trigger found to be firing for a surface that no longer exists is **recorded as a finding**,
   not fixed here.

## Verification notes

_To be filled when the slice runs._

## Open questions

1. Should the map carry **observed cost per trigger** (from `ai_token_usages` on the analytics
   connection) rather than only the shape? That would make it an assessment tool rather than a
   reference, which is closer to what was asked for — but it dates fast, and the numbers live in a
   database this doc cannot cite with `path:line`. Worth deciding before writing.
