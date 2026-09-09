---
title: Decisions — Map of Content
description: The architecture decision timeline (ADRs)
tags: [decision, moc]
status: living
reviewed: 2026-08-14
---

# Decisions (ADRs)

Architecturally significant decisions, each a dated point-in-time record. **ADRs are immutable**: a changed decision gets a *new* ADR, and the old one is marked `status: superseded` with `superseded_by:`. The truth is the whole timeline, not just the latest note.

Only decisions that clear the bar live here — costly to reverse, cross-cutting, or whose rationale isn't obvious from the code. Most day-to-day choices never get an ADR, by design.

## Pattern index

ADRs grouped by the problem they solve, for easier navigation than a flat timeline:

| Pattern | ADRs |
|---|---|
| **Cost guards** | [[idempotent-dispatch-cost-ceiling]] (dispatch-time + daily ceiling) *(pause half superseded)*; [[cost-ceiling-degrades-to-rule-based]] (a hit budget degrades, every other stop pauses) *(its "run questions are still refused" carve-out superseded)*; [[cost-ceiling-answers-run-questions-rule-based]] (a capped day answers questions deterministically too); [[twelve-week-narration-cutoff]] (per-signup backfill depth); [[bounded-self-heal-and-dead-letter]] (execution-time + bounded retry) *(the bound is per row since [[dispatch-claims-the-row]])*; [[dispatch-claims-the-row]] (a conditional claim, and a budget only a person re-arms); [[narration-agents-on-openai-php]] (per-block agent budget); [[per-block-manual-retry]] *(superseded)* |
| **Data isolation** | [[analytics-db-separate-connection]] (metering outlives app resets); [[date-cast-utc-shift]] (UTC off-by-one guard) |
| **Plan** | [[plan-volume-anchors-on-weekly-mean]] (prescribed volume describes the athlete, not their biggest day); [[today-credits-when-earned]] (a day in progress is graded upward only, and the ring counts the days the week holds); [[readiness-clamp-is-advisory]] (the clamp eases today beside the plan, it does not replace it); [[a-clamped-day-is-graded-on-what-it-asked]] (the eased distance is recorded at kickoff and becomes the day's ask); [[a-session-is-the-whole-outing]] (a day's distance covers everything it asks you to run, and no cooldown is prescribed); [[the-plan-follows-the-coaching]] (recovery weeks, quality off the long run's flanks, and quality type by race duration); [[the-plan-knows-its-race-day]] (race day is its own session, the days around it are recovery, and the distance lives on the row); [[the-arc-is-anchored-once]] (the season fixes its arc once, and every week is trained from its place in it); [[a-closed-race-earns-a-recovery-week]] (the week after a race is recovery, not a build week) |
| **Ingest** | [[summary-first-ingest]] (whole history from paged summaries, detail hydrated on demand); [[background-hydration-drain]] (an hourly drain fills the backlog it leaves) |
| **Async / resilience** | [[chained-narration]] (connected narration threads); [[strava-circuit-breaker-rate-limit]] (per-client rate-limit guard); [[live-ingest-read-reserve]] (a quarter of that budget held for live ingest) *(daily half superseded)*; [[backfill-borrows-the-live-reserve]] (the daily bucket holds a flat floor, and backfill spends the rest); [[narrow-trusted-proxy-headers]] (proxy trust behind tunnel); [[trust-all-proxies-cloudflare]] *(superseded)*; [[deferred-recap-windowing]] (window-gated generation) |
| **AI routing** | [[azure-openai-routing]] (per-narrator-kind deployment selection); [[narration-agents-on-openai-php]] (SDK seam + tool calling); [[demo-user-billing-exclusion]] (demo user omitted from auto-billing); [[demo-triggers-served-rule-based]] (demo triggers filled rule-based); [[scoped-run-qa-not-an-analysis-row]] (Q&A scoped by construction, stored outside the row model) |
| **Operability** | [[pause-reason-derives-from-the-dispatch-gate]] (the monitor derives from the gate it reports on) |
| **Notifications** | [[inbox-is-an-always-on-channel]] (the inbox as an unmuteable third channel); [[demo-notifications-are-inbox-only]] (demo routed to the record, never to an interruption) |
| **Ops / deploy** | [[fixed-session-cookie]] (stable cookie name); [[defer-config-cache]] (config cache timing); [[telegram-account-linking]] (signed deep-link token) |
| **Design / branding** | [[dark-is-the-default-ground]] (two grounds on a data-theme attribute) *(default-ground half superseded)*; [[system-is-the-default-ground]] (an unconfigured visitor gets the device's ground); [[ink-grounds-derived-not-listed]] (contrast grounds derived from the render, failing closed); [[tokens-flip-colour-dark-variant-flips-the-rest]] (a colour that flips is a token, everything else may be `dark:`); [[temari-keeps-score-persona]] (voice: friend → training partner who keeps score); [[thread-ball-character-rebrand]] (bunny/Daybreak → thread-ball/Threadwork) *(persona half superseded)* |

## Timeline

_AI cost & flow_
- [[per-block-manual-retry]] — failed AI blocks never auto-retry; retry is manual, to keep LLM cost predictable *(superseded by [[bounded-self-heal-and-dead-letter]])*
- [[bounded-self-heal-and-dead-letter]] — paused blocks stay honestly Pending; failed blocks get a bounded auto-retry, then a per-user dead-letter
- [[idempotent-dispatch-cost-ceiling]] — re-runnable schedulers don't re-bill; a daily USD ceiling caps spend *(its "rows stay Pending past the ceiling" half superseded by [[cost-ceiling-degrades-to-rule-based]])*
- [[cost-ceiling-degrades-to-rule-based]] — a hit daily budget serves rule-based content instead of pausing; every other stop still pauses; default ceiling $5/day *(its "run questions are still refused" carve-out superseded by [[cost-ceiling-answers-run-questions-rule-based]])*
- [[cost-ceiling-answers-run-questions-rule-based]] — a capped day answers a run question deterministically instead of 409-ing it or failing it, and the answer counts toward the day's degraded fills
- [[dispatch-claims-the-row]] — queueing an analysis is one conditional UPDATE two racing dispatchers cannot both win, and only a user-initiated invalidation refills the self-heal budget
- [[pause-reason-derives-from-the-dispatch-gate]] — the reported pause reason is the dispatch gate's own condition list, so a monitor cannot drift from what it reports on
- [[azure-openai-routing]] — per-narrator-kind Azure deployment selection via config/env
- [[chained-narration]] — connected narration threads via prev_narrative + afterDone + resume sweep
- [[deferred-recap-windowing]] — recap rows are Pending until the week/month window closes
- [[demo-user-billing-exclusion]] — demo user excluded from every auto-billing scheduler
- [[demo-triggers-served-rule-based]] — the public demo's "Baca ulang" works but is filled rule-based, never billed
- [[narration-agents-on-openai-php]] — tool-calling narrators stay on openai-php; one block is bounded by steps + tokens
- [[scoped-run-qa-not-an-analysis-row]] — ask-about-this-run is bound to one activity by construction, stored in its own table, rate-limited per user without a per-user cost cap
- [[citations-go-where-the-prose-already-points]] — which narrator carries inline citations is decided by measuring what its real output names, which moved the first prose citation off the post-run speech onto the daily briefing
- [[twelve-week-narration-cutoff]] — narration depth stops at 84 days, and every manual trigger that could reach past it is gated too

_Plan_
- [[plan-volume-anchors-on-weekly-mean]] — the long run is a share of a robust weekly volume, capped by race distance and time on feet, rather than the single longest recent run
- [[today-credits-when-earned]] — today is graded on the same km ratio but only ever upward, and the week's ring counts training rows this week actually holds
- [[readiness-clamp-is-advisory]] — a clamped day renders as a marked step-down beside the session the plan asked for, which stays the thing narrated and graded
- [[a-clamped-day-is-graded-on-what-it-asked]] — the eased distance is recorded at the daily kickoff and becomes the day's ask, so following the step-down credits rather than scoring partial
- [[a-session-is-the-whole-outing]] — the warmup is carved out of a day's prescribed distance rather than added on top, so the card and the graded run are the same outing
- [[the-clamp-explains-itself]] — a readiness step-down gets its own coarsely-fingerprinted line, requested where the ceiling is already computed and replacing the templated note in place
- [[the-plan-follows-the-coaching]] — a race build gets scheduled recovery weeks, quality stays off the long run's flanks, the single quality slot follows projected race duration rather than distance, and the threshold block progresses by phase
- [[the-plan-knows-its-race-day]] — race day becomes its own session type, the day before and every day after it are rest, the race distance is stamped on the row so it outlives the goal, and the readiness clamp never downgrades a race
- [[the-arc-is-anchored-once]] — a season freezes its start week and its starting weekly volume, and every week is prescribed from its position in that stored arc rather than from a schedule rebuilt each Monday
- [[a-closed-race-earns-a-recovery-week]] — the self-scaled arc that follows a race the athlete actually ran opens with one easy week at the deload multiplier, before the build cycle starts

_Data_
- [[analytics-db-separate-connection]] — metering on a separate connection that survives migrate:fresh
- [[date-cast-utc-shift]] — date columns cast `date:Y-m-d` to dodge a UTC off-by-one

_Infra & Strava_
- [[summary-first-ingest]] — a connect stores the whole history from paged summaries; detail, streams and the story layer are hydrated only for runs someone opens
- [[background-hydration-drain]] — an hourly tick hydrates the summary-only backlog newest-first, sized from the read headroom background calls may already spend
- [[unscored-load-is-null-not-zero]] — a week that ran without heart rate reports unknown load; only a week nobody ran reports zero
- [[strava-circuit-breaker-rate-limit]] — Strava rate limit is per-client, so the guard key is global
- [[live-ingest-read-reserve]] — browsing-driven hydration stops at 75% of each read bucket, on its own throttle key, so it cannot starve a fresh run's ingest *(its daily-bucket half superseded by [[backfill-borrows-the-live-reserve]])*
- [[backfill-borrows-the-live-reserve]] — the daily bucket reserves a flat 400-read floor instead of a quarter, so background hydration spends whatever live ingest leaves unspent; the 15-minute burst guard is unchanged
- [[fixed-session-cookie]] — fixed cookie name + Redis prefixes, not APP_NAME-derived
- [[trust-all-proxies-cloudflare]] — trust all proxies behind the Cloudflare tunnel *(superseded by [[narrow-trusted-proxy-headers]])*
- [[narrow-trusted-proxy-headers]] — trust only X-Forwarded-For/Proto/Port; trustHosts rejected because a Host allowlist would fail the healthcheck
- [[defer-config-cache]] — config:cache only at deploy time, never at build or in CI tests
- [[telegram-account-linking]] — link Telegram via a signed deep-link token; prod webhook, dev long-poll

_Notifications_
- [[inbox-is-an-always-on-channel]] — the notification centre is a router channel that is never unwired and never muted
- [[demo-notifications-are-inbox-only]] — the demo identity has no outbound channel, so the public demo's inbox is populated while nothing leaves the app

_Design_
- [[thread-ball-character-rebrand]] — full character replacement (bunny → thread-ball) and palette rename (Daybreak → Threadwork), tying the visual identity to the training arc *(its persona stance superseded by [[temari-keeps-score-persona]]; the visual decisions still stand)*
- [[temari-keeps-score-persona]] — the voice shifts from a soft warm friend to a training partner who holds up the runner's own numbers and names a coast
- [[dark-is-the-default-ground]] — two authored grounds switched by `data-theme` on `<html>`, dark by default, with a ground-reactive semantic layer over a fixed named palette *(its default-ground half superseded by [[system-is-the-default-ground]]; the architecture still stands)*
- [[system-is-the-default-ground]] — with no explicit Settings choice the ground follows `prefers-color-scheme`, and a missing key resolves the same way a stored `system` does
- [[ink-grounds-derived-not-listed]] — the `-ink` tier is derived and audited against grounds read from the stylesheet and the components, and an unclassified background fails the build
- [[tokens-flip-colour-dark-variant-flips-the-rest]] — a ground difference in a colour value is a semantic token; opacity, ring width and whole-property differences may use `dark:`, now wired to `data-theme` rather than the OS setting
