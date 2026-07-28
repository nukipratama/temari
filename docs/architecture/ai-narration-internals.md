---
title: AI narration internals — context builders & the demo filler
description: How prompt signals are assembled (context builders) and how copy is produced without the LLM (demo seed + unconfigured env).
tags: [architecture, ai]
status: living
reviewed: 2026-07-27
code_refs:
  - app/Services/AI/Context/ActivityNarrationContext.php
  - app/Services/AI/Agent/AgentToolbox.php
  - app/Services/AI/Agent/Tools/ActivityTool.php
  - app/Services/AI/Narrators/RunInsightNarrator.php
  - app/Services/Run/Story/BriefingContext.php
  - app/Services/Run/Story/MetricsContext.php
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

[ActivityNarrationContext](app/Services/AI/Context/ActivityNarrationContext.php) is built once per narration call from an `ActivityDetail` ([`fromDetail`](app/Services/AI/Context/ActivityNarrationContext.php#L32)) and collects the run-level signals more than one narrator needs: distance, decoupling, negative-split flag, time-in-zone percentages, and the weather (temp / rain). It also exposes the km conversions ([`distanceKm`](app/Services/AI/Context/ActivityNarrationContext.php#L50), [`distanceKmOrNull`](app/Services/AI/Context/ActivityNarrationContext.php#L59)) so every consumer rounds distance the same way. The exact field list is the constructor — read it there, don't trust this prose.

It is shared by the run-insight, post-run-speech, and card-flavor narrators. Each narrator still adds its *own* keys (mood, PR flags, cadence, rarity, …) on top; the shared object only owns the cross-narrator signals so those stay identical across prompts. See [[vibe-and-mood]] for the per-narrator mood layer and [[cards-collection]] for card flavor.

### Agent tools — signals the model fetches instead of receiving

The per-activity narrators no longer take a pre-computed context. Every number reaches the model through a tool it chose to call — see each narrator's `toolbox()` ([RunInsightNarrator](app/Services/AI/Narrators/RunInsightNarrator.php), [PostRunSpeechNarrator](app/Services/AI/Narrators/PostRunSpeechNarrator.php), [CardFlavorNarrator](app/Services/AI/Narrators/CardFlavorNarrator.php)). The tools are thin readers over the same sources the context object used — several wrap `ActivityNarrationContext` — so the signals are the same ones; what changed is that a run with no heart rate or no elevation no longer pays prompt tokens for the nulls.

Each tool is bound to its subject at construction and declares an argument-free schema ([ActivityTool](app/Services/AI/Agent/Tools/ActivityTool.php)), which is how cross-user reads are prevented: there is no id to pass. The loop, its ceilings, and why the model gets an error payload rather than a failed block are in [[narration-agents-on-openai-php]].

**What still travels in the context** is whatever no tool could serve: a value the *call itself* carries rather than the database (post-run speech's `mood`), plus the continuity line, which stays in the prompt because the content-filter retry has to be able to strip it.

A toolbox is built per call, so it can be shorter when the subject is thinner — a card whose activity has no detail row is offered only `get_card_identity`, rather than four tools that would answer null to everything.

### The briefing family

The four narrators that speak about a *day* rather than a run take the same shape. Their reads are bound to a user as of a date ([UserTool](app/Services/AI/Agent/Tools/UserTool.php)) rather than to an activity, and the per-activity narrators use the same classes for training load and the 28-day baseline: "the runner's load on the day of this run" is the same question as "the runner's load today", asked from a different day.

[WeekStateTool](app/Services/AI/Agent/Tools/WeekStateTool.php) is deliberately **one** tool returning all fifteen `BriefingContext` fields rather than several themed ones. Those fields are produced together by a single query pass, so splitting them would buy nothing but round trips.

The daily greeting keeps its `vibe` in the context, because the *caller* decides which vibe the greeting is for — a tool that recomputed it would be a second source of truth that could disagree. It gains `get_week_state`, so a "you haven't run in a while" greeting can finally tell three days from three weeks.

### The recaps

Weekly, monthly and trend-caption narration held its arithmetic *in the narrator*: month bounds, per-week distance buckets, the mood mix, the fitness arc, the twelve-week series and its four-week deltas. That computation moved wholesale into [MonthTotalsTool](app/Services/AI/Agent/Tools/MonthTotalsTool.php), [WeekTotalsTool](app/Services/AI/Agent/Tools/WeekTotalsTool.php) and [WeeklyTrendTool](app/Services/AI/Agent/Tools/WeeklyTrendTool.php), which is why these three narrators lost more lines than they gained.

The period is fixed at construction — a `WeeklySnapshot`, or a `Y-m` string — so a recap can only ever count the period it was asked about. Weekly and monthly send just the continuity line; the trend caption sends **nothing at all**, since the whole caption is a read.

### The profile narrators

Profile voice, persona summary and PR context complete the set, and all three send an **empty context** — unlike the recaps there was not even a continuity line to keep, since none of them are chained. Their arithmetic moved with them: lifetime stats and the favourite-time bucket, the persona mood mix with its recent-vs-earlier split, and the progression signal were private methods on the narrators and are tools now.

Every narrator now reads rather than receives. What remains in any context is only ever one of two things: a value the *call* carries (post-run speech's `mood`, the daily greeting's `vibe`), or the continuity line.

**The post-run speech is the one narrator deliberately kept short of data.** It used to receive the three insight blocks as prose to synthesize. All four render side by side in the [[run-detail]] lens grid, so being handed the other three made it a fourth telling of the same run — and saying "don't repeat" did not hold, in its own prompt or by removing its splits and zone tools. It now owns a lens the others structurally cannot: the day around the run, and where the run sits against the athlete's own history. Mechanics belong to the other three.

### BriefingContext (per-user-day signals)

[BriefingContext](app/Services/Run/Story/BriefingContext.php) is the dashboard briefing's personalisation layer, built per user as-of a moment ([`forUser`](app/Services/Run/Story/BriefingContext.php#L38)) and serialised straight into the LLM user message ([`toArray`](app/Services/Run/Story/BriefingContext.php#L133), with short keys to keep token cost down). It collects this-week / last-week run-count + km deltas, recovery hours, and form status, plus two computed heuristics:

- the Indonesian **time-of-day bucket** (`subuh` / `pagi` / `siang` / `sore` / `malam`) so a morning briefing reads differently from an evening one ([`bucketFor`](app/Services/Run/Story/BriefingContext.php#L114));
- **consecutive weeks active** — a streak proxy reusing the `WeeklySnapshot` rows we already keep, since we don't track a day-level streak ([`countConsecutiveActiveWeeks`](app/Services/Run/Story/BriefingContext.php#L98)).

Recovery hours is "hours since the most recent activity start", sharper than days-since for a mid-day briefing ([`recoveryHoursForUser`](app/Services/Run/Story/BriefingContext.php#L78)). It feeds the [BriefingNarrator](app/Services/AI/Narrators/BriefingNarrator.php#L159) and [BriefingMascotVoiceNarrator](app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php#L128); the rendered surface is the [[dashboard]] Kata Temari card.

### MetricsContext (briefing call envelope)

[MetricsContext](app/Services/Run/Story/MetricsContext.php) is the lighter wrapper the briefing narrators take as input — user, vibe state, training-load summary, recent verdicts, and the as-of timestamp — from which `BriefingContext::forUser` is then derived. It's the call boundary, not a signal collector.

## The demo filler — copy without the LLM

**Every `AnalysisType` is narrated.** There is no longer a class of types that skips the model: the run-insight blocks and the trend caption were the last holdouts, filled inline from threshold arithmetic even with Azure configured, and they now go through [RunInsightNarrator](app/Services/AI/Narrators/RunInsightNarrator.php) and [TrendCaptionNarrator](app/Services/AI/Narrators/TrendCaptionNarrator.php) like the rest. A block that cannot be narrated stays honestly `Pending` or `Failed` rather than being quietly templated — see [[ai-pipeline]].

What remains is a **demo** path, not a production fallback.

[RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) ([`fillFor`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L28)) covers every `AnalysisType`, picking deterministically (seeded by subject id + discriminator) from Temari-voiced pools and weaving in the subject's real data where available. The run-insight types come from [RuleBasedRunInsights](app/Services/AI/RuleBased/RuleBasedRunInsights.php), which reads the run's own cadence, splits and zones so a seeded demo shows real numbers.

That class is deliberately shallower than the narrator it stands in for: it answers only what a single `ActivityDetail` can, with no rolling pace average over the user's history and no VDOT-derived easy-pace nudge. It is a demo stand-in, not a second implementation to keep in sync.

No *dispatch* path reaches the filler any more: a paused or failing block stays `Pending` / `Failed` instead. It runs in exactly two places — the demo seed below, and the content-filter break in [AnalyzeRowJob](app/Jobs/AI/AnalyzeRowJob.php#L39) / [AnalyzeGroupJob](app/Jobs/AI/AnalyzeGroupJob.php#L131), where a continuity-stripped retry that still trips Azure's output filter degrades to a benign line rather than dead-lettering. That benign line becomes the next `prev_narrative`, which is what breaks the poison loop.

### The demo seed path

The demo seeder stages and fills all Analysis rows under [`AnalysisService::withoutDispatching()`](app/Services/AI/AnalysisService.php#L44), which suppresses every job dispatch ([DemoRunSeeder::seed](database/seeders/Demo/DemoRunSeeder.php#L104)). Rows are staged `Pending` inside that closure and then flat-filled afterward by walking them through the filler ([`backfillWithFiller`](database/seeders/Demo/DemoRunSeeder.php#L292)), so seeding spends zero LLM tokens. The "Baca ulang" button stays live so a reviewer with a configured Azure can trigger one real LLM call per block on demand. The demo user is also held out of billing schedulers — see [[demo-user-billing-exclusion]].

## See also

- [[ai-pipeline]] — the row lifecycle these internals plug into.
- [[ai-usage]] / [[azure-openai-routing]] — token metering and per-narrator deployment routing.
- [[recaps]] / [[chained-narration]] — the weekly/monthly recap kinds the filler and chain advance both cover.
