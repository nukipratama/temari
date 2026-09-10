---
title: Narration analytics are joinable
description: Usage rows carry the analysis id they paid for, the analysis row records which producer wrote it, superseded narrations are kept, and the cross-connection join is done in PHP.
tags: [decision, ai]
status: accepted
reviewed: 2026-09-10
code_refs:
  - app/Actions/AI/RecordTokenUsageAction.php
  - app/Services/AI/NarratedAnalysis.php
  - app/Services/AI/ServedBy.php
  - app/Services/AI/AnalysisService.php
  - app/Models/AI/AnalysisVersion.php
  - app/Console/Commands/PruneAnalyticsCommand.php
  - database/migrations/analytics/2026_09_10_100000_add_analysis_id_and_tool_calls_to_ai_token_usages_table.php
  - database/migrations/2026_09_10_100004_create_analysis_versions_table.php
---

# Narration analytics are joinable

**Status:** Accepted (decided 2026-09-10)

## Context

`/devtools/ai-usage` could report what narration *cost* and `/pulse` could report whether it was
*working*, but neither could answer a question about a single block. Four gaps made that
impossible, all of them missing links rather than missing data:

- A usage row named the narrator (`kind`) and what started the call (`origin`), but not the
  `ai_analyses` row it paid for. Cost per athlete per day was answerable; cost of *this block*
  was not.
- The tool trace an agent run produced existed only as a `narrator.ai.tool_step` log line, which
  is unqueryable and gone with the log rotation.
- Nothing on an analysis row said whether its prose came from an LLM narrator or from
  [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php). Narration
  has two producers ([[cost-ceiling-degrades-to-rule-based]], [[demo-triggers-served-rule-based]])
  and reading the text cannot tell them apart, so a capped day and a healthy one looked identical.
- A re-narration overwrote its predecessor in place, so "did the rewrite improve it?" had no
  before to compare against.

A content-filter event carried only `kind` and `created_at`, so a trip could not be attributed to
an athlete either.

## Decision

**1. A usage row carries the analysis id it paid for.**
[`analysis_id`](database/migrations/analytics/2026_09_10_100000_add_analysis_id_and_tool_calls_to_ai_token_usages_table.php#L20)
on `ai_token_usages`, written by
[RecordTokenUsageAction](app/Actions/AI/RecordTokenUsageAction.php#L40). The id is not threaded
through narrator signatures: [NarratedAnalysis](app/Services/AI/NarratedAnalysis.php) is a
`scoped` ambient holder — the same seam [NarrationOrigin](app/Services/AI/NarrationOrigin.php)
already uses for `origin` — set around the generation by
[AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php#L49) and, per narrator call, by
[`AnalyzeGroupJob::narrating()`](app/Jobs/AI/AnalyzeGroupJob.php#L159), and read at metering time
in [StructuredChatCaller](app/Services/AI/StructuredChatCaller.php#L257). A call made outside a
narration (a run question) reads null and stays unattributed, exactly as an undeclared origin does.

**2. The cross-connection join is done in PHP, not SQL.** `ai_token_usages` lives on the
`analytics` connection and `ai_analyses` on the app one ([[analytics-db-separate-connection]]), so
`analysis_id` is a bare indexed integer with no foreign key — the same shape `user_id` has had
since the first migration, for the same reason. A reader queries one side, collects the ids, and
queries the other; `TokenUsage` deliberately grows no `analysis()` relation, because a cross-schema
`belongsTo` would silently resolve against the wrong connection.

**3. The tool trace becomes a column.**
[`tool_calls`](database/migrations/analytics/2026_09_10_100000_add_analysis_id_and_tool_calls_to_ai_token_usages_table.php#L21),
an ordered list of `{tool, arguments_summary, duration_ms}` accumulated on the run's
[AgentBudget](app/Services/AI/Agent/AgentBudget.php#L164) as
[AgentLoop](app/Services/AI/Agent/AgentLoop.php#L78) executes each call. The budget already
crosses every seam between the loop and the meter, so nothing new is threaded. Arguments are
truncated rather than stored whole: the summary exists to tell two calls of the same tool apart,
not to replay them.

**4. `served_by` lives on the analysis row, not on the usage row.** A usage row exists only when
an LLM was billed, so it can never record that the rule-based filler answered — that is precisely
the case with no usage row. The producer is a property of the content, so it belongs beside the
content. [ServedBy](app/Services/AI/ServedBy.php) is written by every path that settles a row
Done, funnelled through [`markDone()`](app/Services/AI/AnalysisService.php#L224): `llm` by
default, `rule_based` from
[`fillRuleBased()`](app/Services/AI/AnalysisService.php#L797) (demo seed, demo triggers, the
ceiling degrade) and from the two content-filter fallbacks
([row](app/Jobs/AI/AnalyzeRowJob.php#L75), [group](app/Jobs/AI/AnalyzeGroupJob.php#L105)). It is
nullable, because a row that has never been Done was served by neither and historical rows predate
the column.

**5. Superseded narrations are kept.**
[AnalysisVersion](app/Models/AI/AnalysisVersion.php) snapshots content, fingerprint, `served_by`
and `generated_at` in
[`archivePreviousVersion()`](app/Services/AI/AnalysisService.php#L274), on the same transition as
(4) and only when the row already held content — a first narration supersedes nothing. On the app
connection with a real cascading foreign key, so a deleted block leaves no orphan history.

**6. A content-filter event names the athlete.** `user_id` on `ai_content_filter_events`, resolved
through [`AnalysisSubjectMap::ownerId()`](app/Jobs/AI/AnalyzeRowJob.php#L155) — bare and nullable
for the same cross-connection reason as (2).

**7. All of it is bounded by the existing 90-day retention.**
[`analytics:prune`](app/Console/Commands/PruneAnalyticsCommand.php#L35) now also prunes
`analysis_versions` and `devtools_actions`.

## Consequences

- **Enables:** per-block cost, latency and tool trace; an athlete's LLM-vs-rule-based share; a
  re-narration diff; content-filter trips attributable to an athlete. Everything the DV-C/DV-D
  devtools pages need is a query rather than a log grep.
- **Costs:** two columns on the hottest metering table and one new row per re-narration.
  `tool_calls` is the only unbounded-ish payload, and truncating the argument summaries is what
  keeps it small.
- **Gotchas:** `served_by` is null for every row narrated before this shipped, so a reader must
  treat null as "unknown", never as "rule-based". `analysis_id` is null for run questions by
  design, and for any future narration path that forgets to declare itself — the same honest
  unattributed default `origin` has.

## See also

- [[analytics-db-separate-connection]] — why the metering tables are a separate schema at all
- [[analytics-db]] — the connection, its migrations, and how tests rebind it
- [[cost-ceiling-degrades-to-rule-based]] — the path that makes `served_by` worth recording
- [[ai-usage]] — the dashboard that reads all of this
