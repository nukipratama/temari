---
title: Per-athlete narration page
description: /devtools/narration/athletes/{id} — one athlete's spend, their narrations with cost and tool trace, and the four operator actions, each confirmed and audited.
tags: [feature, ai, devtools]
status: living
reviewed: 2026-09-10
code_refs:
  - app/Http/Controllers/NarrationAthleteController.php
  - app/Services/Devtools/AthleteNarrationReport.php
  - app/Services/Devtools/ReArmNarrationAction.php
  - app/Services/Devtools/ReplayNarrationAction.php
  - app/Services/Devtools/CostForecast.php
  - resources/js/pages/Narration/Athlete.tsx
---

# Per-athlete narration page

The overview at `/devtools/narration` answers "what is this costing"; this page answers
"what happened to *this* athlete". It exists because both questions used to require a
tinker session: cost was aggregated per kind and per day, and nothing linked a dollar to
the narration it paid for until [[narration-analytics-are-joinable]] made the join
possible.

Reached at `/devtools/narration/athletes/{userId}`, behind the same
[`devtools` middleware group](routes/web.php#L204) as the rest of the operator screens.
An unknown id 404s; the demo athlete is deliberately *not* excluded, because its blocks
are exactly the ones an operator most often has to look at.

## Header

Today's spend against **whichever ceiling is actually in force** — an operator's
today-only [CeilingOverride](app/Services/AI/CeilingOverride.php) when one is set,
otherwise `azure_openai.daily_cost_ceiling_per_user` — labelled with which of the two it
is, so a raised ceiling never reads as the configured one. Beside it, thirty days of
daily spend as an inline SVG path
([Sparkline](resources/js/components/narration/athlete/Sparkline.tsx)), and
month-to-date with a **projection**: the trailing week's daily rate carried across the
days left in the month ([CostForecast](app/Services/Devtools/CostForecast.php#L20)). It
is labelled a projection rather than a forecast budget, because the recent run rate is
the only evidence behind it.

Every one of those numbers comes from a single per-day query
([`dailyCost()`](app/Services/Devtools/AthleteNarrationReport.php#L358)), so today, the
week, the month and the sparkline never disagree.

## Tab: narrations

The athlete's last 50 `ai_analyses` rows, newest first, with an "older" cursor link
keyed on the row id. Each row carries what the analysis table knows (kind, status,
`served_by`, the narration text, a flag and its reason) joined *in PHP* to what the
analytics schema knows (cost via
[LlmCostCalculator](app/Services/AI/LlmCostCalculator.php), tokens in/out, latency,
agent steps and the `tool_calls` trace). A row with no usage row shows zero cost and no
origin — the honest reading of "nothing was billed for this", which is what a rule-based
fill looks like.

`served_by` is rendered `unknown` when null, never `rule_based`: rows narrated before
that column shipped have no value, and guessing would misreport a capped day.

Kind and status filters travel in the query string and are applied server-side. A row
with `analysis_versions` shows "n earlier version(s)" and expands into a word-level diff
against the version it replaced
([wordDiff](resources/js/components/narration/athlete/wordDiff.ts)) — a small LCS walk
rather than a diff dependency, since narrations are a few hundred words.

## Tab: cost by kind

Each narrator's spend and call count for this athlete over today / 7 days / 30 days.
This is the drill-down the overview's per-kind table cannot do, because that one sums
across athletes.

## Tab: attention, and the four actions

Three buckets — failed but still auto-retrying, dead-lettered, and in-flight rows past
[`STALE_IN_FLIGHT_HOURS`](app/Models/AI/Analysis.php#L78) — each with the action that
clears it:

| Action | What it runs |
|---|---|
| retry all failed | [`ReArmNarrationAction::retryFailed()`](app/Services/Devtools/ReArmNarrationAction.php#L31) — attempts to 0, re-dispatch with `invalidate: false` |
| re-arm dead-lettered | [`reArmDeadLettered()`](app/Services/Devtools/ReArmNarrationAction.php#L37) — the same, narrowed to spent-budget rows: `ai:recover` for one athlete |
| resync Strava | [SyncActivitiesJob](app/Jobs/Strava/SyncActivitiesJob.php), the same per-user dispatch "Sync now" uses |
| today-only ceiling | [`CeilingOverride::set()`](app/Services/AI/CeilingOverride.php#L20), expiring at local midnight, plus a clear |

Every one of them takes a **confirm step rendered in the page** — never the browser's
`confirm()`, which cannot state what is about to happen — and writes a row through
[DevtoolsActionRecorder](app/Services/Devtools/DevtoolsActionRecorder.php) before
flashing. The last 20 of those rows and the active override sit at the bottom of the
tab, so the page shows its own history.

## Flag to replay

A flagged narration row carries a **replay** button: re-narrate that exact block with
[`AnalysisOrigin::Replay`](app/Services/AI/AnalysisOrigin.php), queued through the
ordinary job path, so the row reads Queued until the new answer lands and then shows the
diff against what it replaced (the snapshot is automatic in
[`markDone()`](app/Services/AI/AnalysisService.php#L224)).

A replay is the operator's spend, not the athlete's, so it is measured against its own
app-wide `azure_openai.replay_daily_cap` and never against the athlete's slice —
[`ReplayNarrationAction`](app/Services/Devtools/ReplayNarrationAction.php) reads the same
numbers [AnalysisService](app/Services/AI/AnalysisService.php#L840) enforces at dispatch,
so the confirm step can quote the block's last cost and today's replay total, and the
button refuses in place rather than asking for a confirmation it would not honour. The
demo athlete's replay resolves through the rule-based filler like every other demo
trigger ([[demo-triggers-served-rule-based]]), so a public account can never be replayed
onto the bill.

## See also

- [[ai-usage]] — the overview this page drills down from
- [[narration-analytics-are-joinable]] — the schema that makes a per-block answer possible
- [[cost-ceiling-degrades-to-rule-based]] — why an athlete has a ceiling to override at all
- [[feedback]] — where the flag on a narration row comes from
