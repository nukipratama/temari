---
title: AI narration internals — context builders & the demo filler
description: How prompt signals are assembled (context builders) and how copy is produced without the LLM (demo seed + unconfigured env).
tags: [architecture, ai]
status: living
reviewed: 2026-08-03
code_refs:
  - app/Services/AI/Context/ActivityNarrationContext.php
  - app/Services/AI/Agent/AgentToolbox.php
  - app/Services/AI/Agent/Tools/ActivityTool.php
  - app/Services/AI/Narrators/RunInsightNarrator.php
  - app/Services/Run/Story/BriefingContext.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
  - app/Services/AI/RuleBased/RuleBasedRunInsights.php
  - app/Services/AI/AnalysisType.php
  - app/Services/AI/AnalysisService.php
  - database/seeders/Demo/DemoRunSeeder.php
---

# AI narration internals — context builders & the demo filler

Two internals sit *under* the [[ai-pipeline]]: how a narrator's LLM prompt gets its **signals**, and how a row gets **content when there's no LLM call**. The pipeline note covers the row lifecycle, dispatch, idempotency, and retry; this note complements it and does not repeat it.

## Context builders — shared prompt signals

A narrator's prompt is two halves: a static system prompt plus a per-subject **context** object that the LLM reads to make the copy specific. The context-assembly logic is pulled out of the narrators into small readonly value objects so that (a) signals derived from the same raw data are computed in exactly one place, and (b) the per-narrator context array a narrator hands to the LLM stays byte-stable — the field extraction can't drift between narrators that share it, which keeps prompts deterministic and cacheable.

### ActivityNarrationContext (per-run signals)

[ActivityNarrationContext](app/Services/AI/Context/ActivityNarrationContext.php) is built once per narration call from an `ActivityDetail` ([`fromDetail`](app/Services/AI/Context/ActivityNarrationContext.php#L44)) and collects the run-level signals more than one narrator needs: distance, decoupling, negative-split flag, time-in-zone percentages, and the weather (temp / rain). It also exposes the km conversions ([`distanceKm`](app/Services/AI/Context/ActivityNarrationContext.php#L74), [`distanceKmOrNull`](app/Services/AI/Context/ActivityNarrationContext.php#L83)) so every consumer rounds distance the same way. The exact field list is the constructor — read it there, don't trust this prose.

It is shared by the run-insight, post-run-speech, and card-flavor narrators. Each narrator still adds its *own* keys (mood, PR flags, cadence, rarity, …) on top; the shared object only owns the cross-narrator signals so those stay identical across prompts. See [[vibe-and-mood]] for the per-narrator mood layer and [[cards-collection]] for card flavor.

### Agent tools — signals the model fetches instead of receiving

The per-activity narrators no longer take a pre-computed context. Every number reaches the model through a tool it chose to call — see each narrator's `toolbox()` ([RunInsightNarrator](app/Services/AI/Narrators/RunInsightNarrator.php), [PostRunSpeechNarrator](app/Services/AI/Narrators/PostRunSpeechNarrator.php), [CardFlavorNarrator](app/Services/AI/Narrators/CardFlavorNarrator.php)). The tools are thin readers over the same sources the context object used — several wrap `ActivityNarrationContext` — so the signals are the same ones; what changed is that a run with no heart rate or no elevation no longer pays prompt tokens for the nulls.

Each tool is bound to its subject at construction and declares an argument-free schema ([ActivityTool](app/Services/AI/Agent/Tools/ActivityTool.php)), which is how cross-user reads are prevented: there is no id to pass. The loop, its ceilings, and why the model gets an error payload rather than a failed block are in [[narration-agents-on-openai-php]].

**What still travels in the context** is whatever no tool could serve: a value the *call itself* carries rather than the database (post-run speech's `mood`), plus the continuity line, which stays in the prompt because the content-filter retry has to be able to strip it.

A toolbox is built per call, so it can be shorter when the subject is thinner — a card whose activity has no detail row is offered only `get_card_identity`, rather than four tools that would answer null to everything. The same applies by ingest state: [RunQuestionNarrator](app/Services/AI/Narrators/RunQuestionNarrator.php) drops the splits, laps, zone and terrain reads on a `summary`-state run and keeps the run summary plus the history reads, since the stream pipeline has not run yet. See [[run-qa]].

Any tool that hands the model a raw duration or pace in seconds also carries a `_formatted` twin built with the same helper the UI calls, so a quoted figure can never drift from what the page beside it shows — the pattern [ProgressionSignalTool](app/Services/AI/Agent/Tools/ProgressionSignalTool.php#L81) established for `delta_sec`/`delta_formatted`. `DurationFormatter::hms()` ([DurationFormatter.php](app/Services/Run/Metrics/DurationFormatter.php#L10)) covers durations (`PersonalRecordsTool.php#L34`, `RunSummaryTool.php#L37`, `LapsTool.php#L64`) and `PaceFormatter::format()` ([PaceFormatter.php](app/Services/Run/Metrics/PaceFormatter.php#L9), the same call [pace.ts](resources/js/lib/pace.ts) mirrors) covers seconds-per-km (`TrainingPacesTool.php#L48`, `RunSummaryTool.php#L41`, `WeekTotalsTool.php#L46`, `PlanContextTool.php#L81`). Each tool's description names the formatted field as the only one to quote and keeps the raw seconds for judging size and direction.

### The briefing family

The four narrators that speak about a *day* rather than a run take the same shape. Their reads are bound to a user as of a date ([UserTool](app/Services/AI/Agent/Tools/UserTool.php)) rather than to an activity, and the per-activity narrators use the same classes for training load and the 28-day baseline: "the runner's load on the day of this run" is the same question as "the runner's load today", asked from a different day.

[WeekStateTool](app/Services/AI/Agent/Tools/WeekStateTool.php) is deliberately **one** tool returning all fifteen `BriefingContext` fields rather than several themed ones. Those fields are produced together by a single query pass, so splitting them would buy nothing but round trips.

The daily greeting keeps its `vibe` in the context, because the *caller* decides which vibe the greeting is for — a tool that recomputed it would be a second source of truth that could disagree. It gains `get_week_state`, so a "you haven't run in a while" greeting can finally tell three days from three weeks.

### The recaps

Weekly and monthly narration held its arithmetic *in the narrator*: month bounds, per-week distance buckets, the mood mix and the fitness arc. That computation moved wholesale into [MonthTotalsTool](app/Services/AI/Agent/Tools/MonthTotalsTool.php) and [WeekTotalsTool](app/Services/AI/Agent/Tools/WeekTotalsTool.php), which is why these narrators lost more lines than they gained.

The period is fixed at construction — a `WeeklySnapshot`, or a `Y-m` string — so a recap can only ever count the period it was asked about. Weekly and monthly send just the continuity line; the trend caption sends **nothing at all**, since the whole caption is a read.

### The profile narrators

Profile voice completes the set, and sends an **empty context** — unlike the recaps there was not even a continuity line to keep, since it is not chained. Their arithmetic moved with them: lifetime stats and the favourite-time bucket, the persona mood mix with its recent-vs-earlier split, and the progression signal were private methods on the narrators and are tools now. The persona read was a separately billed narrator of its own until it was merged into the profile voice, which now carries [PersonaMixTool](app/Services/AI/Agent/Tools/PersonaMixTool.php) beside its own three.

A narrator can also carry a contract the JSON schema cannot express. `StructuredChatCaller` takes an optional `validator` on [ChatCallOptions](app/Services/AI/ChatCallOptions.php#L45): it runs on the decoded answer, and a complaint replays the conversation once with the answer and the complaint appended and tools forbidden, then throws if the rewrite fails too ([StructuredChatCaller](app/Services/AI/StructuredChatCaller.php#L149)). The profile voice is the first user — its two evidence slots only bind the prose because a figure check enforces them.

Every narrator now reads rather than receives. What remains in any context is only ever one of two things: a value the *call* carries (post-run speech's `mood`, the daily greeting's `vibe`), or the continuity line.

**The post-run speech is the one narrator deliberately kept short of data.** It used to receive the three insight blocks as prose to synthesize. All four render side by side in the [[run-detail]] lens grid, so being handed the other three made it a fourth telling of the same run — and saying "don't repeat" did not hold, in its own prompt or by removing its splits and zone tools. It now owns a lens the others structurally cannot: the day around the run, and where the run sits against the athlete's own history. Mechanics belong to the other three.

The neighbour it collides with is not on that page at all. `get_week_state` serves both this narrator and the daily briefing, and the briefing *opens* on the week-over-week pair, so a runner who read home and then opened today's run met the same two figures twice in one session. Both keep the tool — the week genuinely does explain some runs — but the post-run prompt now ranks the run-scoped reads first and demotes the pair to a last resort that has to say what the week *changed* about this run ([PostRunSpeechNarrator](app/Services/AI/Narrators/PostRunSpeechNarrator.php#L63)).

### BriefingContext (per-user-day signals)

[BriefingContext](app/Services/Run/Story/BriefingContext.php) is the dashboard briefing's personalisation layer, built per user as-of a moment ([`forUser`](app/Services/Run/Story/BriefingContext.php#L59)) and serialised straight into the LLM user message ([`toArray`](app/Services/Run/Story/BriefingContext.php#L231), with short keys to keep token cost down). It collects this-week / last-week run-count + km deltas, recovery hours, and form status, plus two computed heuristics:

- the **time-of-day bucket** (`early_morning` / `morning` / `midday` / `evening` / `night`) so a morning briefing reads differently from an evening one ([`bucketFor`](app/Services/Run/Story/BriefingContext.php#L212));
- **consecutive weeks active** — a streak proxy reusing the `WeeklySnapshot` rows we already keep, since we don't track a day-level streak ([`countConsecutiveActiveWeeks`](app/Services/Run/Story/BriefingContext.php#L196)).

Recovery hours is "hours since the most recent activity start", sharper than days-since for a mid-day briefing — now computed by [RecoveryWindow::forUser](app/Services/Run/Story/RecoveryWindow.php#L35) and passed in. `BriefingContext::forUser` is called from [WeekStateTool::handle](app/Services/AI/Agent/Tools/WeekStateTool.php#L48), one of the agent tools [BriefingMascotVoiceNarrator](app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php) reads from; the rendered surface is the [[dashboard]] mascot-voice block.

## The demo filler — copy without the LLM

**Every `AnalysisType` is narrated.** There is no longer a class of types that skips the model: the run-insight blocks were the last holdout, filled inline from threshold arithmetic even with Azure configured, and they now go through [RunInsightNarrator](app/Services/AI/Narrators/RunInsightNarrator.php) like the rest. A block that cannot be narrated stays honestly `Pending` or `Failed` rather than being quietly templated — see [[ai-pipeline]].

What remains is a **demo** path, not a production fallback.

[RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) ([`fillFor`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L39)) covers every `AnalysisType`, picking deterministically (seeded by subject id + discriminator) from Temari-voiced pools and weaving in the subject's real data where available. The run-insight types come from [RuleBasedRunInsights](app/Services/AI/RuleBased/RuleBasedRunInsights.php), which reads the run's own cadence, splits and zones so a seeded demo shows real numbers.

**`post_run_speech` is drawn from two slots, not one pool.** It is the only filler type the History feed renders once per run, so a visitor scrolls dozens of them side by side and a single pool of whole sentences repeats verbatim within one screen. The line is instead an opener carrying the distance ([`POST_RUN_OPENERS`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L130)) plus an independent second beat ([`POST_RUN_CLOSERS`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L154)), each selected under its own hash salt of the activity id ([`postRunSpeech`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L119)), then the data-driven coda. 15 × 16 short lines give 240 pairings rather than 12 sentences, and no closer asserts anything about how the run went, so no pairing can contradict its opener or the coda. Selection stays a pure function of the activity id, so a given run reads the same forever.

That class is deliberately shallower than the narrator it stands in for: it answers only what a single `ActivityDetail` can, with no rolling pace average over the user's history and no VDOT-derived easy-pace nudge. It is a demo stand-in, not a second implementation to keep in sync.

No *dispatch* path reaches the filler any more: a paused or failing block stays `Pending` / `Failed` instead. It runs in exactly two places — the demo seed below, and the content-filter break in [AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php#L44) / [AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php#L78), where a continuity-stripped retry that still trips Azure's output filter degrades to a benign line rather than dead-lettering. That benign line becomes the next `prev_narrative`, which is what breaks the poison loop.

### The demo seed path

The demo seeder stages and fills all Analysis rows under [`AnalysisService::withoutDispatching()`](app/Services/AI/AnalysisService.php#L64), which suppresses every job dispatch ([DemoRunSeeder::seed](database/seeders/Demo/DemoRunSeeder.php#L117)). Rows are staged `Pending` inside that closure and then flat-filled afterward by walking them through the filler ([`backfillWithFiller`](database/seeders/Demo/DemoRunSeeder.php#L327)), so seeding spends zero LLM tokens. `trend_read` and the three `plan_*_voice` types are filled the same way but bypass that walk, going straight through `AnalysisService::requestRuleBased()`/`PlanNarrationRequester::ensureDemoFilled()` instead (`F7`, since neither has a demo-reachable dispatch path otherwise). The "Reread" button stays live for the demo, but its trigger is filled through the same filler rather than dispatched, so no demo click reaches Azure — see [[demo-triggers-served-rule-based]]. The demo user is also held out of billing schedulers — see [[demo-user-billing-exclusion]].

## See also

- [[ai-pipeline]] — the row lifecycle these internals plug into.
- [[ai-usage]] / [[azure-openai-routing]] — token metering and per-narrator deployment routing.
- [[recaps]] / [[chained-narration]] — the weekly/monthly recap kinds the filler and chain advance both cover.
