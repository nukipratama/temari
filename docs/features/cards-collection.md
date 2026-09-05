---
title: Card — earned per run, seen as a share image
description: How a run becomes a Card (rarity, badges, special move), and the share button on the run detail page that is now the only way to see one. Browsing/filtering by rarity happens inline on History, not a dedicated page.
tags: [feature, cards]
status: living
reviewed: 2026-09-01
code_refs:
  - resources/js/components/card/ShareCardModal.tsx
  - resources/js/components/card/RouteGlyph.tsx
  - resources/js/components/card/RunCardMini.tsx
  - resources/js/components/run/RunHero.tsx
---

# Card — earned per run, seen as a share image

Every Strava run that syncs becomes a **card** — a trading-card view of that run with a rarity, a few badges, and a Temari-written "special move" name. There is no standalone card gallery or URL, and since `PS8` no full-face card is drawn in the app at all: a card surfaces as the compact [RunCardMini](../../resources/js/components/card/RunCardMini.tsx) leading each row of [[run-history]] (server-side rarity filter lives there too) and Login's sample, and in full only as the **share image** the run's own detail page can generate.

## System dependencies

- **Gamification engine** — rarities, badges, and special moves are assigned by [[gamification]] during ingest.
- **AI narration** — the card's flavor quote comes from an `Analysis` row ([[ai-pipeline]]).
- **Data model** — `RunCard`, `Activity`, `Rarity` enum, edition numbering in [[data-model]].
- **Share** — `public_share_url` is a signed route. There are **two** share-image renderers: [shareCard.ts](../../resources/js/lib/shareCard.ts) draws the image the user downloads on a client canvas, and [RunCardImageRenderer](../../app/Services/Run/Story/RunCardImageRenderer.php) builds the same card as an SVG rasterised through Imagick + librsvg for the post-run Telegram photo. Both render the `story` format at 1080x1920 and name the same three font families, which is why the server image needs those fonts installed (see [resources/brand/fonts/README.md](../../resources/brand/fonts/README.md)). Neither is full-bleed: the card takes 90% of each axis and sits on a `--color-cream-deep` mat carrying the `--shadow-e4` elevation, so the exported image reads like the mounted card in the app rather than a flat poster. They are separate runtimes with hand-ported geometry, so they can drift; [RunCardImageRendererTest](../../tests/Unit/Services/Run/Story/RunCardImageRendererTest.php) pins the parts that must stay in step, mat and shadow included.

## Card presenter — the single owner of the card shape

[CardPresenter](../../app/Services/Run/Story/CardPresenter.php) holds the rarity counts, both edition strategies (a bulk index map and a single-card aggregate query), the column whitelist that keeps internal columns out of Inertia, the mood fallback, and the `CardFlavor` payload. The run-detail full view is now its only reader — Today's featured-card panel was cut in `PP3`.

## The share button (on [[run-detail]])

`RunController::show` in [RunController.php](../../app/Http/Controllers/RunController.php) enriches the run's `RunCard` with the `CardFlavor` analysis, its edition (`index`/`total` within its rarity), and `public_share_url` — which despite the name is the ordinary authenticated `activities.show` route, not a signed public page; no public per-card page exists. `PS8` rebuilt the page to the prototype's section list, which draws **no collectible block anywhere** — so the on-page card, its rarity chip and its lore column are gone, and with them the `Kartu`, `KartuMount` and `ZoneBar` components they were the only readers of (their names then; `W6` renamed the surviving card vocabulary to English). The `CardFlavor` quote survives as the share image's caption.

What is left is one control: a **Share** button in the run hero ([RunHero.tsx](../../resources/js/components/run/RunHero.tsx)), rendered only when the run actually has a card, opening [ShareCardModal](../../resources/js/components/card/ShareCardModal.tsx) — which draws the downloadable share image (card or route layout) on a canvas. The prototype draws no share button and no dialog at all; keeping one is a deliberate divergence (`cut-list.md` §4), because without it a generated card would be permanently unviewable and Login's "a card for every run" teaser would promise nothing. The pack-tear reveal modal and the "why this earned [rarity]" explainer were cut earlier, in `PP3`.

## Where rarities and badges come from

The card's `rarity`, `special_move`, and `badges` are assigned upstream by the gamification engine when the run is ingested — this page is read-only over that data. See [[gamification]] for the rarity rules and [[data-model]] for the `run_cards` table.

## See also

- [[run-history]] — where every earned card is actually browsed and filtered by rarity
- [[run-detail]] — the run page hosting a card's full view
