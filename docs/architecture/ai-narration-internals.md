---
title: AI narration internals — context builders & rule-based fallback
description: How prompt signals are assembled (context builders) and how copy is produced without the LLM (rule-based fallback + demo seed).
tags: [architecture, ai]
status: living
reviewed: 2026-06-20
code_refs:
  - app/Services/AI/Context/ActivityNarrationContext.php
  - app/Services/Run/Story/BriefingContext.php
  - app/Services/Run/Story/MetricsContext.php
  - app/Services/AI/RuleBased/RuleBasedNarrationFiller.php
  - app/Services/AI/RuleBased/RuleBasedInsightBuilder.php
  - app/Services/AI/AnalysisType.php
  - app/Services/AI/AnalysisService.php
  - database/seeders/Demo/DemoRunSeeder.php
---

# AI narration internals — context builders & rule-based fallback

Two internals sit *under* the [[ai-pipeline]]: how a narrator's LLM prompt gets its **signals**, and how a row gets **content when there's no LLM call**. The pipeline note covers the row lifecycle, dispatch, idempotency, and retry; this note complements it and does not repeat it.

## Context builders — shared prompt signals

A narrator's prompt is two halves: a static system prompt plus a per-subject **context** object that the LLM reads to make the copy specific. The context-assembly logic is pulled out of the narrators into small readonly value objects so that (a) signals derived from the same raw data are computed in exactly one place, and (b) the per-narrator context array a narrator hands to the LLM stays byte-stable — the field extraction can't drift between narrators that share it, which keeps prompts deterministic and cacheable.

### ActivityNarrationContext (per-run signals)

[ActivityNarrationContext](app/Services/AI/Context/ActivityNarrationContext.php) is built once per narration call from an `ActivityDetail` ([`fromDetail`](app/Services/AI/Context/ActivityNarrationContext.php#L32)) and collects the run-level signals more than one narrator needs: distance, decoupling, negative-split flag, time-in-zone percentages, and the weather (temp / rain). It also exposes the km conversions ([`distanceKm`](app/Services/AI/Context/ActivityNarrationContext.php#L50), [`distanceKmOrNull`](app/Services/AI/Context/ActivityNarrationContext.php#L59)) so every consumer rounds distance the same way. The exact field list is the constructor — read it there, don't trust this prose.

It is shared by the run-insight, post-run-speech, and card-flavor narrators (e.g. [RunInsightNarrator](app/Services/AI/Narrators/RunInsightNarrator.php#L131)). Each narrator still adds its *own* keys (mood, PR flags, cadence, rarity, …) on top; the shared object only owns the cross-narrator signals so those stay identical across prompts. See [[vibe-and-mood]] for the per-narrator mood layer and [[cards-collection]] for card flavor.

### BriefingContext (per-user-day signals)

[BriefingContext](app/Services/Run/Story/BriefingContext.php) is the dashboard briefing's personalisation layer, built per user as-of a moment ([`forUser`](app/Services/Run/Story/BriefingContext.php#L38)) and serialised straight into the LLM user message ([`toArray`](app/Services/Run/Story/BriefingContext.php#L133), with short keys to keep token cost down). It collects this-week / last-week run-count + km deltas, recovery hours, and form status, plus two computed heuristics:

- the Indonesian **time-of-day bucket** (`subuh` / `pagi` / `siang` / `sore` / `malam`) so a morning briefing reads differently from an evening one ([`bucketFor`](app/Services/Run/Story/BriefingContext.php#L114));
- **consecutive weeks active** — a streak proxy reusing the `WeeklySnapshot` rows we already keep, since we don't track a day-level streak ([`countConsecutiveActiveWeeks`](app/Services/Run/Story/BriefingContext.php#L98)).

Recovery hours is "hours since the most recent activity start", sharper than days-since for a mid-day briefing ([`recoveryHoursForUser`](app/Services/Run/Story/BriefingContext.php#L78)). It feeds the [BriefingNarrator](app/Services/AI/Narrators/BriefingNarrator.php#L159) and [BriefingMascotVoiceNarrator](app/Services/AI/Narrators/BriefingMascotVoiceNarrator.php#L128); the rendered surface is the [[dashboard]] Kata Temari card.

### MetricsContext (briefing call envelope)

[MetricsContext](app/Services/Run/Story/MetricsContext.php) is the lighter wrapper the briefing narrators take as input — user, vibe state, training-load summary, recent verdicts, and the as-of timestamp — from which `BriefingContext::forUser` is then derived. It's the call boundary, not a signal collector.

## Rule-based fallback — copy without the LLM

Two distinct situations produce content with no token spend. Both route through the same deterministic generators, so demo output matches what an unconfigured-env user would see.

### Which types are rule-based

[`AnalysisType::isRuleBased()`](app/Services/AI/AnalysisType.php#L162) is the source of truth (read it, don't hand-copy): the run-insight blocks and the trend caption are *always* rule-based — they're pure arithmetic over the run's own data, never worth an LLM call. [RuleBasedInsightBuilder](app/Services/AI/RuleBased/RuleBasedInsightBuilder.php) generates these from threshold comparisons against the activity and the user's rolling averages ([`runInsights`](app/Services/AI/RuleBased/RuleBasedInsightBuilder.php#L59), [`trendCaption`](app/Services/AI/RuleBased/RuleBasedInsightBuilder.php#L431)), emitting the same plain-string format the frontend expects.

### When the fallback fires

In [`AnalysisService::dispatchRow()`](app/Services/AI/AnalysisService.php#L158) the row is filled inline (no queue, no tokens) when the type [`isRuleBased()` or auto-dispatch is off](app/Services/AI/AnalysisService.php#L169):

- **Always-rule-based types** take the builder path via [`ruleBasedContent()`](app/Services/AI/AnalysisService.php#L200), even with Azure configured.
- **LLM types under unconfigured / off env** — when [`autoDispatchEnabled()`](app/Services/AI/AnalysisService.php#L370) is false (Azure URI/key empty, tripped cost ceiling, or dispatch suppressed), the LLM types are filled by [RuleBasedNarrationFiller](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php) ([`fillFor`](app/Services/AI/RuleBased/RuleBasedNarrationFiller.php#L33)) instead. The filler covers every `AnalysisType`, delegating the run-insight types to the same builder so the output matches production, and otherwise picking deterministically (seeded by subject id + discriminator) from Temari-voiced pools, woven with the subject's real data where available.

> Subtlety: when Azure is unconfigured the [[ai-pipeline]] describes LLM rows staying `Pending`. The filler is what turns "pending" into real demo/dev copy — it runs when something explicitly fills the rows (the demo seed below), or inline for a `firstOrCreate` of an LLM type while dispatch is suppressed.

### The demo seed path

The demo seeder stages and fills all Analysis rows under [`AnalysisService::withoutDispatching()`](app/Services/AI/AnalysisService.php#L44), which suppresses every job dispatch ([DemoRunSeeder::seed](database/seeders/Demo/DemoRunSeeder.php#L104)). Rows are staged `Pending` inside that closure and then flat-filled afterward by walking them through the filler ([`backfillWithFiller`](database/seeders/Demo/DemoRunSeeder.php#L292)), so seeding spends zero LLM tokens. The "Baca ulang" button stays live so a reviewer with a configured Azure can trigger one real LLM call per block on demand. The demo user is also held out of billing schedulers — see [[demo-user-billing-exclusion]].

## See also

- [[ai-pipeline]] — the row lifecycle these internals plug into.
- [[ai-usage]] / [[azure-openai-routing]] — token metering and per-narrator deployment routing.
- [[recaps]] / [[chained-narration]] — the weekly/monthly recap kinds the filler and chain advance both cover.
