---
title: Card collection (Koleksi/Kartu)
description: The run-card grid, featured banner, rarity filter, and per-card full view on the run detail page.
tags: [feature, cards]
status: living
reviewed: 2026-07-06
code_refs:
  - resources/js/pages/Koleksi/Kartu.tsx
  - app/Http/Controllers/CardController.php
  - resources/js/components/card/Kartu.tsx
  - resources/js/components/card/FeaturedCardHero.tsx
  - resources/js/components/card/CardReveal.tsx
  - resources/js/components/card/PackWrapper.tsx
  - resources/js/components/card/ShareCardModal.tsx
  - resources/js/components/card/RouteGlyph.tsx
  - resources/js/components/card/ZoneBar.tsx
  - resources/js/components/card/KartuMini.tsx
  - resources/js/components/ConfettiBurst.tsx
---

# Card collection (Koleksi/Kartu)

Every Strava run that syncs becomes a **kartu** — a trading-card view of that run with a rarity, a few badges, and a Temari-written "special move" name. `/kartu` is the whole collection; tapping any card opens its run in [[run-detail]], where the card gets its own full-view section (see below). There is no separate card detail page or URL — a card and its run are the same page.

## The grid (`/kartu`)

Served by the `index` method of [CardController](../../app/Http/Controllers/CardController.php). It paginates the user's cards 24-per-page **newest-first** (`orderByDesc('id')`), and — when a `?rarity=` query is present — narrows to a single rarity. Three derived props ride along: `rarityCounts` (per-rarity totals), `featuredCard`, and the `selectedRarity` echo. Edition numbering ("#3 of 12 epics") is computed in one window-function pass by the private `editionIndexMap` method, so there's no N+1.

The page component [KoleksiKartu](../../resources/js/pages/Koleksi/Kartu.tsx) renders:

- A **featured banner** (`SlimBanner` → [FeaturedCardHero](../../resources/js/components/card/FeaturedCardHero.tsx)) showing the headline card. With no rarity filter the controller's `featuredCard` method picks by `FIELD(rarity, 'legendary', …)` then newest — so the rarest recent card leads. Its Temari flavor line streams through [AnalysisStatus](../../resources/js/components/temari/AnalysisStatus.tsx) (see [[ai-pipeline]]).
- A **rarity filter** (`RarityFilter`) — pills per rarity with live counts, plus a client-side search box and a sort selector (Terbaru / Tingkat / Nama). Search + sort run on the already-loaded page via a `useDeferredValue` pass so typing stays smooth.
- The **card grid** — each cell is a [Kartu](../../resources/js/components/card/Kartu.tsx), the full card face: route glyph ([RouteGlyph](../../resources/js/components/card/RouteGlyph.tsx)), HR-zone bar ([ZoneBar](../../resources/js/components/card/ZoneBar.tsx)), badges, and stats. ([KartuMini](../../resources/js/components/card/KartuMini.tsx) is the compact variant used elsewhere, e.g. the dashboard.)
- A **legendary tease** card when the user owns zero legendaries, hinting how to unlock one.

Tapping an **epic or legendary** cell fires a viewport-wide confetti burst via [ConfettiBurst](../../resources/js/components/ConfettiBurst.tsx) — `triggerBurstFor` only sets the burst key for those two rarities; commons stay quiet.

## The card's full view (on [[run-detail]])

There is no standalone `/kartu/{card}` route. A card's full view — the big card + lore — lives in its own section on `/aktivitas/{activity}`, right below the hero, built by `RunController::show` in [RunController.php](../../app/Http/Controllers/RunController.php): it enriches the run's `RunCard` with the `CardFlavor` analysis, its edition (`index`/`total` within its rarity), and a signed `public_share_url`. [Show.tsx](../../resources/js/pages/Runs/Show.tsx) lays out a sky hero with the big card + actions on the left, and the lore on the right: the special-move title, the streamed flavor quote (re-analyzable here), and a "Kenapa [rarity]" block explaining each badge.

Two actions sit under the hero:

- **Bagikan** opens [ShareCardModal](../../resources/js/components/card/ShareCardModal.tsx), which draws a downloadable share image (card or route layout) on a canvas.
- **Buka ulang kartu** re-arms the pack-tear reveal: it POSTs `/api/kartu/{card}/replay`, then reloads the `pendingReveal` prop so [CardReveal](../../resources/js/components/card/CardReveal.tsx) replays. That reveal wraps the card in a draggable foil ([PackWrapper](../../resources/js/components/card/PackWrapper.tsx)) the user tears open; rare/epic/legendary reveals are "theatrical" and trigger their own confetti.

## Where rarities and badges come from

The card's `rarity`, `special_move`, and `badges` are assigned upstream by the gamification engine when the run is ingested — this page is read-only over that data. See [[gamification]] for the rarity rules and [[data-model]] for the `run_cards` table.
