---
title: Features — Map of Content
description: Hub for user-facing feature walkthroughs
tags: [feature, moc]
status: living
reviewed: 2026-06-20
---

# Features

One walkthrough per user-facing area — what it does and where it lives in the code. Start from [[DESIGN]] for how these fit together.

## Notes

_Pages_
- [[landing]] — /login as the public landing page: the Past You promise, the sourced legal copy, and the honest throttle path
- [[dashboard]] — the home screen: the Past You verdict and its evidence, today's session, then vitals / last run / card
- [[run-history]] — Feed/Calendar, weekly snapshots, week deep link
- [[run-detail]] — single run: story + adaptive claims lenses, route map, splits, HR zones
- [[run-qa]] — ask about this run: run-derived suggested questions, an answer scoped to that one activity
- [[profile]] — persona, profile voice
- [[settings]] — Settings hub (Telegram toggles, HR-zone entry, account deletion)
- [[settings-hr-zones]] — HR zones (Karvonen zones)
- [[legal-pages]] — the four public documents (terms, privacy, AI use, training disclaimer)
- [[ai-usage]] — token usage dashboard (ops-gated)
- [[race-projection]] — Race, the goal race and fitted-Riegel finish-time projection, plus the 90-day fitness trend
- [[plan-periodizer]] — Plan, the deterministic periodizer, readiness clamp, and volume redistribution

_Collection_
- [[cards-collection]] — the Card's reveal + full view (rarities, badges, special moves); browsing/filtering by rarity is inline on [[run-history]]
- [[records]] — PRs, progression chart
- [[targets-accessories]] — the accessory unlock catalog, granted at ingest and drawn nowhere since `PP2`

_Systems_
- [[recaps]] — weekly/monthly/persona narratives across the app
- [[gamification]] — milestones, unlocks, rarities, special moves engine
- [[vibe-and-mood]] — the daily vibe matrix + run mood that drive Temari's tone
- [[strava-connect]] — OAuth, sync, webhook
- [[onboarding]] — first-connect wizard and the DB-backed gate
- [[telegram-notifications]] — link a Telegram account, the master switch and channel mutes, post-run + weekly-recap push
- [[notification-inbox]] — /inbox, the durable record of what Temari sent, with celebration replay
- [[streak-reminders]] — Saturday nudges for at-risk weekly streaks, on any wired channel
- [[temari-mascot]] — the face and the brand mark, the app's whole identity art
- [[installed-app-shell]] — Home-Screen install: edge-to-edge status bar, launch image, top bar with back button, touch feel, edge-swipe back
