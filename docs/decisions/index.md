---
title: Decisions — Map of Content
description: The architecture decision timeline (ADRs)
tags: [decision, moc]
status: living
reviewed: 2026-09-21
---

# Decisions (ADRs)

Architecturally significant decisions, each a dated point-in-time record. **ADRs are immutable**: a changed decision gets a *new* ADR, and the old one is marked `status: superseded` with `superseded_by:`. The truth is the whole timeline, not just the latest note.

Only decisions that clear the bar live here — costly to reverse, cross-cutting, or whose rationale isn't obvious from the code. Most day-to-day choices never get an ADR, by design.

## Pattern index

ADRs grouped by the problem they solve, for easier navigation than a flat timeline:

| Pattern | ADRs |
|---|---|
| **Cost guards** | [[idempotent-dispatch-cost-ceiling]] (dispatch-time + daily ceiling) *(pause half superseded)*; [[cost-ceiling-degrades-to-rule-based]] (a hit budget degrades, every other stop pauses) *(its "run questions are still refused" carve-out superseded)*; [[app-wide-ceiling-above-the-per-athlete-one]] (a total ceiling above the per-athlete slice); [[cost-ceiling-answers-run-questions-rule-based]] (a capped day answers questions deterministically too); [[twelve-week-narration-cutoff]] (per-signup backfill depth); [[history-narrates-on-demand]] (pre-connect history narrates only when asked for); [[narration-follows-the-athlete-not-the-run]] (scheduled narration follows app visits, not runs) *(superseded)*; [[narration-spends-only-on-active-athletes]] (every narration path spends only on active athletes, caught up on return); [[bounded-self-heal-and-dead-letter]] (execution-time + bounded retry) *(the bound is per row since [[dispatch-claims-the-row]])*; [[dispatch-claims-the-row]] (a conditional claim, and a budget only a person re-arms); [[narration-agents-on-openai-php]] (per-block agent budget); [[per-block-manual-retry]] *(superseded)* |
| **Data isolation** | [[analytics-db-separate-connection]] (metering outlives app resets); [[date-cast-utc-shift]] (UTC off-by-one guard) |
| **Plan** | [[plan-volume-anchors-on-weekly-mean]] (prescribed volume describes the athlete, not their biggest day); [[recent-single-run-cap-stages-long-run-progression]] (recent single-run capacity stages Long progression without becoming the weekly baseline); [[today-credits-when-earned]] (a day in progress is graded upward only, and the ring counts the days the week holds); [[readiness-clamp-is-advisory]] *(superseded)*; [[the-eased-session-leads]] (a recorded ease is the day's session everywhere, and the week total sums it) *(today-only half amended by [[todays-ease-stays-a-stepdown]])*; [[todays-ease-stays-a-stepdown]] (today's recorded ease renders as a step-down like an unrecorded one, and a blind-clamp guard holds both while recent load is unscored); [[a-day-is-graded-on-distance-and-intent]] (a day is graded on the session's intent as well as its distance, and adaptation reads distance alone); [[a-clamped-day-is-graded-on-what-it-asked]] (the eased distance is recorded at kickoff and becomes the day's ask); [[a-session-is-the-whole-outing]] (a day's distance covers everything it asks you to run, and no cooldown is prescribed); [[the-plan-follows-the-coaching]] (recovery weeks, quality off the long run's flanks, and quality type by race duration); [[the-plan-knows-its-race-day]] (race day is its own session, the days around it are recovery, and the distance lives on the row); [[the-arc-is-anchored-once]] (the season fixes its arc once, and every week is trained from its place in it); [[a-closed-race-earns-a-recovery-week]] (the week after a race is recovery, not a build week); [[a-goalless-arc-does-not-ramp]] (a goal-less arc holds flat and a race block's ramp is bounded); [[the-block-opens-on-a-computed-date]] (the race block is fixed by distance and opens on a computed date, not a season boundary); [[a-race-block-never-prescribes-below-habit]] (a race block averages at least recent actual volume, climbs to its readiness long run, and gives way to a recorded load deload); [[a-credited-day-shows-its-result]] (each session type is credited by what it asks, and a finished day drops its step-down) *(the credited-day half of [[readiness-clamp-is-advisory]] superseded)* |
| **Ingest** | [[summary-first-ingest]] (whole history from paged summaries, detail hydrated on demand); [[background-hydration-drain]] (an hourly drain fills the backlog it leaves); [[recap-waits-for-hydration]] (a recap waits for its week to finish hydrating) *(pickup timing superseded)*; [[weekly-recaps-resume-at-drain-completion]] (closed recaps resume when the drain finishes); [[hydrate-on-connect]] (a fresh connect starts its own drain immediately, reusing the cron's per-user path) |
| **Async / resilience** | [[kickoff-catch-up-is-upsert-only]] (an hourly sweep recreates the kickoff rows a missed scheduler minute never staged); [[chained-narration]] (connected narration threads); [[strava-circuit-breaker-rate-limit]] (per-client rate-limit guard); [[live-ingest-read-reserve]] (a quarter of that budget held for live ingest) *(daily half superseded)*; [[backfill-borrows-the-live-reserve]] (the daily bucket holds a flat floor, and backfill spends the rest); [[narrow-trusted-proxy-headers]] (proxy trust behind tunnel); [[trust-all-proxies-cloudflare]] *(superseded)*; [[deferred-recap-windowing]] (window-gated generation) |
| **AI routing** | [[azure-openai-routing]] (per-narrator-kind deployment selection); [[narration-agents-on-openai-php]] (SDK seam + tool calling); [[demo-user-billing-exclusion]] (demo user omitted from auto-billing); [[demo-triggers-served-rule-based]] (demo triggers filled rule-based); [[scoped-run-qa-not-an-analysis-row]] (Q&A scoped by construction, stored outside the row model) |
| **Operability** | [[pause-reason-derives-from-the-dispatch-gate]] (the monitor derives from the gate it reports on); [[narration-analytics-are-joinable]] (per-block cost, tool trace and producer become queryable) |
| **Notifications** | [[inbox-is-an-always-on-channel]] (the inbox as an unmuteable third channel); [[demo-notifications-are-inbox-only]] (demo routed to the record, never to an interruption); [[the-briefing-arrives-when-you-run]] (the morning push lands at the athlete's own median start time, and generates nothing); [[the-briefing-also-goes-to-telegram]] (the briefing routes to every outbound channel, and the app-icon badge counts inbox rows) |
| **Ops / deploy** | [[fixed-session-cookie]] (stable cookie name); [[defer-config-cache]] (config cache timing); [[telegram-account-linking]] (signed deep-link token) |
| **Design / branding** | [[dark-is-the-default-ground]] (two grounds on a data-theme attribute) *(default-ground half superseded)*; [[system-is-the-default-ground]] (an unconfigured visitor gets the device's ground); [[ink-grounds-derived-not-listed]] (contrast grounds derived from the render, failing closed); [[tokens-flip-colour-dark-variant-flips-the-rest]] (a colour that flips is a token, everything else may be `dark:`); [[temari-keeps-score-persona]] (voice: friend → training partner who keeps score); [[thread-ball-character-rebrand]] (bunny/Daybreak → thread-ball/Threadwork) *(persona half superseded)* |

## Timeline

_AI cost & flow_
- [[per-block-manual-retry]] — failed AI blocks never auto-retry; retry is manual, to keep LLM cost predictable *(superseded by [[bounded-self-heal-and-dead-letter]])*
- [[bounded-self-heal-and-dead-letter]] — paused blocks stay honestly Pending; failed blocks get a bounded auto-retry, then a per-user dead-letter
- [[idempotent-dispatch-cost-ceiling]] — re-runnable schedulers don't re-bill; a daily USD ceiling caps spend *(its "rows stay Pending past the ceiling" half superseded by [[cost-ceiling-degrades-to-rule-based]])*
- [[cost-ceiling-degrades-to-rule-based]] — a hit daily budget serves rule-based content instead of pausing; every other stop still pauses; default ceiling $5/day *(its "run questions are still refused" carve-out superseded by [[cost-ceiling-answers-run-questions-rule-based]])*
- [[app-wide-ceiling-above-the-per-athlete-one]] — an app-wide $5/day total sits above the per-athlete slice and degrades every athlete at once when it trips
- [[cost-ceiling-answers-run-questions-rule-based]] — a capped day answers a run question deterministically instead of 409-ing it or failing it, and the answer counts toward the day's degraded fills
- [[narration-analytics-are-joinable]] — usage rows carry the analysis id and tool trace, the analysis row records which producer wrote it, superseded narrations are kept, and the cross-connection join is done in PHP
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
- [[narration-follows-the-athlete-not-the-run]] — scheduled narration is spent on athletes who opened the app in the last 7 days; plan rows, metrics and compliance still run for everyone *(superseded by [[narration-spends-only-on-active-athletes]])*
- [[narration-spends-only-on-active-athletes]] — a synced run, the recap kickoffs and self-heal spend only on active athletes too, one verdict ranks every reason a run is kept from the LLM, and the first visit after a gap catches the athlete up without a push
- [[history-narrates-on-demand]] — pre-connect runs hydrate fully but narrate rule-based, with the LLM read left to the athlete and held until the window has hydrated
- [[kickoff-catch-up-is-upsert-only]] — an hourly upsert-only sweep recreates the kickoff rows a missed scheduler minute never staged; self-heal still owns filling them

_Plan_
- [[plan-volume-anchors-on-weekly-mean]] — the long run is a share of a robust weekly volume, capped by race distance and time on feet, rather than the single longest recent run
- [[recent-single-run-cap-stages-long-run-progression]] — a planned Long rises at most 10% above recent single-run capacity without changing the weekly baseline or other session types
- [[today-credits-when-earned]] — today is graded on the same km ratio but only ever upward, and the week's ring counts training rows this week actually holds
- [[readiness-clamp-is-advisory]] — a clamped day renders as a marked step-down beside the session the plan asked for, which stays the thing narrated and graded *(superseded by [[the-eased-session-leads]])*
- [[a-clamped-day-is-graded-on-what-it-asked]] — the eased distance is recorded at the daily kickoff and becomes the day's ask, so following the step-down credits rather than scoring partial
- [[a-session-is-the-whole-outing]] — the warmup is carved out of a day's prescribed distance rather than added on top, so the card and the graded run are the same outing
- [[the-clamp-explains-itself]] — a readiness step-down gets its own coarsely-fingerprinted line, requested where the ceiling is already computed and replacing the templated note in place
- [[the-plan-follows-the-coaching]] — a race build gets scheduled recovery weeks, quality stays off the long run's flanks, the single quality slot follows projected race duration rather than distance, and the threshold block progresses by phase
- [[the-plan-knows-its-race-day]] — race day becomes its own session type, the day before and every day after it are rest, the race distance is stamped on the row so it outlives the goal, and the readiness clamp never downgrades a race
- [[the-arc-is-anchored-once]] — a season freezes its start week and its starting weekly volume, and every week is prescribed from its position in that stored arc rather than from a schedule rebuilt each Monday
- [[a-closed-race-earns-a-recovery-week]] — the self-scaled arc that follows a race the athlete actually ran opens with one easy week at the deload multiplier, before the build cycle starts
- [[a-goalless-arc-does-not-ramp]] — a season with no race holds at 1.0 with deload dips instead of ramping, and a race block's build ramp is capped at 1.4
- [[the-block-opens-on-a-computed-date]] — a race arc runs the general cycle until a block fixed by distance opens, and block open is a computed date inside one continuous season
- [[a-race-block-never-prescribes-below-habit]] — a race block averages at least the athlete's twelve-week actual mean, keeps its last recovery week off the last Build week, holds the long run through Peak, takes its long-run goal from the plan, records the floor a load deload sets aside, and holds increases above habit until recent load is scored *(amends [[the-plan-follows-the-coaching]], [[the-block-opens-on-a-computed-date]] and [[plan-volume-anchors-on-weekly-mean]])*
- [[a-credited-day-shows-its-result]] — quality days credit their best single run, long days sum but need one run at 70% to read done, and a credited day ships no step-down *(supersedes the credited-day half of [[readiness-clamp-is-advisory]])*
- [[the-eased-session-leads]] — a recorded ease is the day's session on every surface, graded, summed into the week total and narrated through the clamp line until credit, with the original as context *(its today-before-credit half amended by [[todays-ease-stays-a-stepdown]])*
- [[a-day-is-graded-on-distance-and-intent]] — a deterministic intent verdict moves a credited day between done, partial and overreached, while the weekly adaptation keeps reading distance alone
- [[todays-ease-stays-a-stepdown]] — today's recorded ease, before credit, renders exactly like an unrecorded one on both Home and Plan, and a blind-clamp guard holds the clamp (render and recorder) while recent load is still unscored

_Data_
- [[analytics-db-separate-connection]] — metering on a separate connection that survives migrate:fresh
- [[date-cast-utc-shift]] — date columns cast `date:Y-m-d` to dodge a UTC off-by-one

_Infra & Strava_
- [[summary-first-ingest]] — a connect stores the whole history from paged summaries; detail, streams and the story layer are hydrated only for runs someone opens
- [[background-hydration-drain]] — a tick hydrates the summary-only backlog sized from the read headroom background calls may already spend *(its ordering half superseded by [[chronological-hydration-drain]])*
- [[chronological-hydration-drain]] — the drain hydrates oldest-first, so a card's PR flag, mood and Past You comparison are right the moment a run lands
- [[recap-waits-for-hydration]] — a weekly recap is held back while its week still has runs the pipeline owes a detail fetch, bounded by a wall-clock grace window *(pickup timing superseded by [[weekly-recaps-resume-at-drain-completion]])*
- [[weekly-recaps-resume-at-drain-completion]] — drain settlement immediately re-offers closed hydrated weeks; hourly self-heal remains the fallback
- [[unscored-load-is-null-not-zero]] — a week that ran without heart rate reports unknown load; only a week nobody ran reports zero
- [[strava-circuit-breaker-rate-limit]] — Strava rate limit is per-client, so the guard key is global
- [[live-ingest-read-reserve]] — browsing-driven hydration stops at 75% of each read bucket, on its own throttle key, so it cannot starve a fresh run's ingest *(its daily-bucket half superseded by [[backfill-borrows-the-live-reserve]])*
- [[backfill-borrows-the-live-reserve]] — the daily bucket reserves a flat 400-read floor instead of a quarter, so background hydration spends whatever live ingest leaves unspent; the 15-minute burst guard is unchanged
- [[hydrate-on-connect]] — a fresh connect's backfill dispatches an immediate per-user hydration batch instead of waiting for the next cron tick, reusing the cron's headroom and ordering unchanged
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
