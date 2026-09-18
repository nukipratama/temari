---
title: The backfill drain hydrates oldest-first
description: strava:hydrate-backlog walks a user's summary-only backlog oldest-first by activity date, so a run's card PR flag, mood and Past You comparison are right the moment it lands instead of needing a later replay.
tags: [decision, run, ai, strava]
status: accepted
reviewed: 2026-09-18
code_refs:
  - app/Console/Commands/Strava/HydrateBacklogCommand.php
  - app/Listeners/DispatchPostRunAnalysis.php
  - app/Actions/Run/Story/RecomputeCardClaimsAction.php
  - app/Services/Run/Story/RunCardFactory.php
  - app/Services/AI/HistoryNarrationGate.php
---

# The backfill drain hydrates oldest-first

**Status:** Accepted (2026-09-18). Supersedes the ordering half of
[[background-hydration-drain]]; that note's headroom pacing, cadence, even
split and give-up guard are unchanged and stay there.

## Context

The #1000 audit found that a day-one backfill produced wrong derived state:
phantom `pr_set` cards, a mood frozen on a PR that no longer existed, recaps
closing on the fallback line, and narration written mid-hydration citing a
comparison the app would no longer make. All of it traced to one cause:
[HydrateBacklogCommand::hydrateFor()](../../app/Console/Commands/Strava/HydrateBacklogCommand.php)
hydrated newest-first, so a run was judged against a past that had not loaded
yet — `RunCardFactory::build()`'s `pr_set` and `Temari::postRunLine()`'s mood
are computed once, immediately, inside the same transaction as detail ingest
([ActivityPipeline::ingest()](../../app/Services/Run/Ingest/ActivityPipeline.php)),
with no gate holding them back the way narration is held.

PR #1022 (#1011/#1012) patched the consumers rather than the order: a chronological replay
([RecomputeCardClaimsAction](../../app/Actions/Run/Story/RecomputeCardClaimsAction.php))
re-judges every card once nothing earlier is left to hydrate, and narration is
held ([HistoryNarrationGate::awaitsOlderHydration()](../../app/Services/AI/HistoryNarrationGate.php))
until the older history within reach has landed. That fixed what the athlete
sees, but the phantom is still minted and then corrected, once, at the end of
every backfill — the replay ran on every drain, not as a rare safety net.

## Decision

**Hydrate the backlog oldest-first, by `activity_details.start_date_local`.**
[HydrateBacklogCommand::hydrateFor()](../../app/Console/Commands/Strava/HydrateBacklogCommand.php#L113)
orders its correlated subquery ascending instead of descending. Everything
else about the drain — the headroom-paced budget, the even split across users,
the give-up guard on `detail_fail_count`, the 15-minute cadence — is
unchanged; only the order within one user's backlog moved.

With that change, `RunCardFactory`'s and `Temari`'s ingest-time computation is
correct *by construction*: `PersonalRecords::detectAndStore()` and
`RunCardFactory::build()`'s sticky `pr_set` check both read whatever
`personal_records` rows already exist, and under oldest-first every
chronologically-earlier run has already been hydrated and scored before a
later one lands. A run genuinely without a PR is never flagged one in the
first place, so [RecomputeCardClaimsAction](../../app/Actions/Run/Story/RecomputeCardClaimsAction.php)
has nothing to fix. It stays as the safety net for a run that still arrives
out of chronological order — a live run synced mid-drain while older history
is backfilling, or a backdated upload — which is now the rare case rather
than the routine one.

### Why this doesn't reopen the narration-latency question

[[background-hydration-drain]] chose newest-first for two reasons. The first —
recent runs are what the athlete is about to look at — is the accepted cost
here: under oldest-first a new athlete's most recent runs get stream-derived
detail (splits, HR, the topo card) last, and the first rebuild measured about
1.5 hours to drain. Summary numbers (distance, time, pace) are unaffected,
since [[summary-first-ingest]] writes them immediately regardless of
hydration order.

The second reason — `DispatchPostRunAnalysis` staggering every backfilled
run's LLM cascade 6 minutes apart, so draining oldest-first would park the
few runs that actually narrate behind hours of slots belonging to runs that
fill rule-based for free — no longer holds, but not because it stopped
mattering: [[history-narrates-on-demand]]'s 2026-09-18 update already made
narration wait on hydration regardless of drain order (`awaitsOlderHydration`,
released by `ai:self-heal`, not by the stagger). What the reordering exposed
was a latent bug in the stagger reservation itself — see below.

## The stagger reservation was firing for runs that never use it

`DispatchPostRunAnalysis::handle()` computed
[`$delaySec`](../../app/Listeners/DispatchPostRunAnalysis.php#L78) by calling
`StaggerBackfillAction` for *every* backfilled run, before knowing whether
that run would dispatch to the LLM at all. Both call sites that read
`$delaySec` — `requestCardFlavor()`'s real-narration branch and
`dispatchActivityGroup()`'s chain-advance branch — are gated behind
`! $ruleBased && ! $stageOnly`, so a rule-based fill (`TooOld`, `PreConnect`,
`Demo`) or a staged/held row (`Inactive`) never reads the value it just
reserved. The reservation is a single shared per-user "next slot" cache entry
(`StaggerBackfillAction`), so every wasted call still advanced it.
`AwaitingBacklog` used to be staged/held here too; since #1054 it dispatches
like `Eligible` (the fresh-connect early pass, narrated ahead of its own older
history — see docs/decisions/history-narrates-on-demand.md), so it reads
`$delaySec` same as any other real dispatch.

Under newest-first this was mostly harmless: a backfill's LLM-eligible runs
(bounded to the last `RecentlyActiveUsers::ACTIVE_WINDOW_DAYS` before connect,
per [[history-narrates-on-demand]]) landed early in the drain, before many
rule-based reservations had piled up. Under oldest-first, the LLM-eligible
runs are the *last* ones the drain reaches — by construction, they are the
runs whose older history has just finished hydrating — so every rule-based
reservation made earlier in the same drain sat ahead of them in the queue.
Measured on a 186-run history, roughly 180 wasted reservations would have
pushed the last 7 days' real narration back by hours, for no reason: none of
those reservations were ever spent.

The fix reserves a slot only for a run that will actually use it:

```php
$delaySec = ($isBackfill && ! $ruleBased && ! $stageOnly) ? ($this->staggerBackfill)($activity->user_id) : 0;
```

This makes narration latency independent of hydration order, which is what
the original stagger reasoning assumed newest-first bought for free. A held
run's eventual release goes through `ai:self-heal`
([SelfHealer::resumePerActivity()](../../app/Services/AI/SelfHealer.php)),
which spaces its own dispatches with a much smaller, sweep-local
`SWEEP_SPACING_SECONDS` (5s) constant and never touches
`StaggerBackfillAction` — so a held run's release was never at risk of
double-reserving a slot, before or after this fix.

**The stagger is still needed, at a smaller scale than it was sized for.** Its
config comment ("100 backfilled activities span ~10 hours") predates
[[history-narrates-on-demand]]'s 7-day carve-out; a first-connect backfill now
narrates at most a handful of runs automatically, not hundreds. What it still
protects against: the cluster of last-7-days runs on a first connect can
become `Eligible` within the same drain tick once the older history clears,
and a returning, already-connected athlete's multi-day catch-up sync can
bring in several genuinely-`Eligible` (non-historical) backfilled runs at
once. Both are real, if far smaller, bursts than the mechanism was built for;
retuning `ai.backfill_stagger_seconds` for that smaller scale is a separate,
deliberate change, not a consequence of this one.

## Consequences

- **Enables:** a card's PR flag, mood and (once its own narration is
  eligible) Past You comparison are correct the moment a run lands, with no
  replay needed for the routine backfill case.
- **Costs:** a new athlete's most recently-run history gets stream-derived
  detail last, for the duration of one drain (~1.5 hours measured). Volume
  numbers are unaffected.
- **`RecomputeCardClaimsAction` becomes a true safety net.** It still fires
  for genuine out-of-order arrivals (a live run mid-drain, a backdated
  upload), but no longer on every backfill.
- **The stagger reservation no longer leaks across unrelated runs.** A rule-
  based or staged run's ingest never advances the shared per-user slot it
  will never spend.

## See also

- [[background-hydration-drain]] — the drain this supersedes on ordering only
- [[history-narrates-on-demand]] — the hydration-hold narration already relied on, independent of drain order
- [[summary-first-ingest]] — why volume numbers are unaffected regardless of hydration order
- [[backfill-borrows-the-live-reserve]] — the read-budget pacing the drain still uses, unchanged
