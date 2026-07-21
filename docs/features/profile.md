---
title: Profile (Aku)
description: The runner's identity page — Temari's profile voice, lifetime stats, 12-week persona mix, top PRs, accessories, Strava status
tags: [feature, profile]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Aku.tsx
  - app/Http/Controllers/ProfileController.php
  - resources/js/components/PersonaBar.tsx
  - resources/js/components/temari/AnalysisStatus.tsx
  - resources/js/components/card/PrCard.tsx
  - resources/js/components/temari/Temari.tsx
---

# Profile (Aku)

The "Aku" page (`/aku`) is the runner's about-me: who they are, how Temari sees them, their lifetime totals, a 12-week mood persona, recent PRs, and their accessory collection. Server entry is [ProfileController](app/Http/Controllers/ProfileController.php) (`__invoke`), rendering the [Aku](resources/js/pages/Aku.tsx) page.

**Navigation:** `route('profile')` → `/aku`. Named route: `profile`.

## System dependencies

- **AI narration** — `profileVoice` (`AkuProfileVoice`) and `personaSummary` (`PersonaSummary`) are `Analysis` rows from the [[ai-pipeline]].
- **Gamification** — PRs come from [[gamification]]; accessories from `UserUnlock` rows.
- **Settings** — the Telegram toggles, HR-zone entry, and account deletion moved to the [[settings]] hub; Aku links to it.
- **Data model** — `PersonalRecord`, `UserUnlock` shapes in [[data-model]].

## Identity + Kata Temari tentang kamu

The header eyebrow is built from first-run date and months-since-first-run, over an "{firstName} Runner, ceritanya." headline. Below it a `HeroPanel` pairs the [Temari](resources/js/components/temari/Temari.tsx) mascot (pose `proud`) with **"★ Kata Temari tentang kamu"** — the AI profile voice (`profileVoice`), rendered through [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) `onSky` as an italic quote. Two chips below show Strava status (`identity.strava_connected`) and "Gabung sejak …".

Server side, `ProfileController::resolveProfileVoice` looks up the `AkuProfileVoice` analysis with **no discriminator** (one row per user) and returns `Analysis::toPayload`. Note: this voice is intentionally manual/"stale by design" — the numbers on the page are live, but the prose only refreshes on "Baca ulang". See [[recaps]] and [[ai-pipeline]].

## Stats trio

Three `StatCard`s: **Total km**, **Total lari**, **Lari terjauh**. The controller computes these in one aggregate query over `ActivityDetail` (`SUM(distance)`, `MAX(distance)`, `MIN(start_date_local)`) plus `user->activities()->count()` for the run count, converting meters to km in the payload.

## Persona — 12 minggu terakhir

The "Persona" section renders [PersonaBar](resources/js/components/PersonaBar.tsx): a single stacked bar of mood slices (`personaMix`), each colored by `MOOD_FILL`, with a legend of `MOOD_LABEL` + percent. The mix comes from `PersonaSummaryNarrator::personaMix($user)`. Below the bar, an optional `personaSummary` AnalysisStatus block narrates the mix in Temari's voice.

`ProfileController::resolvePersonaSummary` keys this analysis by **ISO week** (`isoFormat('GGGG-[W]WW')`) as its discriminator — moods don't shift hourly, so the narration is cached per week even though the narrator pulls 12 weeks of history. Empty mix → PersonaBar shows "Belum ada cukup lari buat baca personamu."

## Top PRs

When present, "Rekor terbaru" shows up to three `RekorMini` cards — each a [PrCard](resources/js/components/card/PrCard.tsx) with the category label (`PR_CATEGORY_LABELS`), formatted value, set-date, and a link to the source run. Supplied by `ProfileController::topPrs` (`PersonalRecord` ordered by `set_at desc`, limit `TOP_PR_COUNT` = 3). Full list is `/rekor` — see [[records]].

## Accessories

The "Aksesori" section (`AksesoriStrip`) shows the full unlock catalog (`config('temari_unlocks')`) with a "{n} / {total} kebuka" chip; unlocked entries are highlighted, locked ones show their criteria behind a lock icon. `unlocks` comes from the user's `UserUnlock` rows. Links to `/aksesori` to equip. See [[targets-accessories]] and [[temari-mascot]].

## Pengaturan

Aku no longer carries a settings entry point. The Telegram notification panel and the Zona HR entry once lived inline here, then behind a single row at the bottom of the page; both now live on the [[settings]] hub, reached from the avatar menu ([UserMenu](../../resources/js/components/UserMenu.tsx)) next to "Keluar". Settings is an account action, not a profile section — putting it beside logout makes it reachable from every page instead of only this one.

## Notes / gotchas

- `profileVoice` has **no discriminator** (one analysis per user, refreshed manually); `personaSummary` is **per-ISO-week**. Don't confuse the two cache keys.
- The mascot here renders via the shared [Temari](resources/js/components/temari/Temari.tsx) wrapper, so any equipped accessory shows up automatically.
- Both voice blocks lean on the same [AnalysisStatus](resources/js/components/temari/AnalysisStatus.tsx) state machine as the rest of the app — see [[ai-pipeline]] and [[data-model]] (`Analysis`, `PersonalRecord`, `UserUnlock`).
