---
title: Kartu — full view on the run page
description: How a run becomes a Kartu (rarity, badges, special move) and its full view on the run detail page. Browsing/filtering by rarity happens inline on History, not a dedicated page.
tags: [feature, cards]
status: living
reviewed: 2026-08-19
code_refs:
  - resources/js/components/card/Kartu.tsx
  - resources/js/components/card/FeaturedCardHero.tsx
  - resources/js/components/card/ShareCardModal.tsx
  - resources/js/components/card/RouteGlyph.tsx
  - resources/js/components/card/ZoneBar.tsx
  - resources/js/components/card/KartuMini.tsx
  - resources/js/components/ConfettiBurst.tsx
---

# Kartu — full view on the run page

Every Strava run that syncs becomes a **kartu** — a trading-card view of that run with a rarity, a few badges, and a Temari-written "special move" name. There is no standalone card gallery or URL: a card is shown wherever its run is — leading each row of [[run-history]] (server-side rarity filter lives there too) and, in full, as a section of [[run-detail]]. Tapping a card in either place opens (or is already on) `route('activities.show', activity)`.

## System dependencies

- **Gamification engine** — rarities, badges, and special moves are assigned by [[gamification]] during ingest.
- **AI narration** — the featured card flavor and the "Kenapa [rarity]" explanations come from `Analysis` rows ([[ai-pipeline]]).
- **Data model** — `RunCard`, `Activity`, `Rarity` enum, edition numbering in [[data-model]].
- **Share** — `public_share_url` is a signed route. There are **two** share-image renderers: [shareCard.ts](../../resources/js/lib/shareCard.ts) draws the image the user downloads on a client canvas, and [RunCardImageRenderer](../../app/Services/Run/Story/RunCardImageRenderer.php) builds the same card as an SVG rasterised through Imagick + librsvg for the post-run Telegram photo. Both render the `story` format at 1080x1920 and name the same three font families, which is why the server image needs those fonts installed (see [resources/brand/fonts/README.md](../../resources/brand/fonts/README.md)). Neither is full-bleed: the card takes 90% of each axis and sits on a `--color-cream-deep` mat carrying the `--shadow-e4` elevation, so the exported image reads like the mounted card in the app rather than a flat poster. They are separate runtimes with hand-ported geometry, so they can drift; [RunCardImageRendererTest](../../tests/Unit/Services/Run/Story/RunCardImageRendererTest.php) pins the parts that must stay in step, mat and shadow included.

## Card presenter — the single owner of the card shape

[CardPresenter](../../app/Services/Run/Story/CardPresenter.php) holds the rarity counts, both edition strategies (a bulk index map and a single-card aggregate query), the column whitelist that keeps internal columns out of Inertia, the mood fallback, and the `CardFlavor` payload. The run-detail full view, [FeaturedKartuPanel](../../resources/js/components/dashboard/FeaturedKartuPanel.tsx) (via [FeaturedCardHero](../../resources/js/components/card/FeaturedCardHero.tsx)), both read it, so the two can't drift apart.

## The card's full view (on [[run-detail]])

A card's full view — the big card + lore — lives in its own section on `/activities/{activity}`, right below the hero, built by `RunController::show` in [RunController.php](../../app/Http/Controllers/RunController.php): it enriches the run's `RunCard` with the `CardFlavor` analysis, its edition (`index`/`total` within its rarity), and a signed `public_share_url`. [Show.tsx](../../resources/js/pages/Runs/Show.tsx) lays out a sky hero with the big card + actions on the left, and the lore on the right: the special-move title, the streamed flavor quote (re-analyzable here), and a "Kenapa [rarity]" block explaining each badge. The full-face render is [Kartu](../../resources/js/components/card/Kartu.tsx): route glyph ([RouteGlyph](../../resources/js/components/card/RouteGlyph.tsx)), HR-zone bar ([ZoneBar](../../resources/js/components/card/ZoneBar.tsx)), badges, and stats. [KartuMini](../../resources/js/components/card/KartuMini.tsx) is the compact variant used elsewhere — [[run-history]]'s list row, the dashboard.

One action sits under the hero: **Bagikan** opens [ShareCardModal](../../resources/js/components/card/ShareCardModal.tsx), which draws a downloadable share image (card or route layout) on a canvas. The pack-tear reveal modal that used to sit beside it was cut in `PP3` — the prototype draws no reveal, so a card is only ever seen in place.

## Where rarities and badges come from

The card's `rarity`, `special_move`, and `badges` are assigned upstream by the gamification engine when the run is ingested — this page is read-only over that data. See [[gamification]] for the rarity rules and [[data-model]] for the `run_cards` table.

## See also

- [[run-history]] — where every earned card is actually browsed and filtered by rarity
- [[run-detail]] — the run page hosting a card's full view
