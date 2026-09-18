---
title: Share card — one server renderer, three print styles
description: The card a run is shared as. One SVG renderer behind an authenticated PNG endpoint, three print styles the athlete picks, five run forms, two aspects.
tags: [feature, cards]
status: living
reviewed: 2026-09-18
code_refs:
  - app/Services/Run/Story/RunCardImageRenderer.php
  - app/Services/Run/Story/Card/CardFacts.php
  - app/Services/Run/Story/Card/Svg.php
  - app/Http/Controllers/RunCardImageController.php
  - resources/js/components/card/ShareCardModal.tsx
---

# Share card — one server renderer, three print styles

A run's card leaves the app as a PNG: the preview in the share modal, the file the athlete
downloads or copies, and the photo attached to the post-run Telegram message are all the same
bytes from the same renderer. The client canvas port that used to draw its own copy retired with
`#968`, so there is nothing left to drift.

## The renderer

[RunCardImageRenderer](../../app/Services/Run/Story/RunCardImageRenderer.php) resolves the run's
facts once, hands them to one of the three style renderers, and rasterises the returned SVG
through Imagick + librsvg at twice the export size before sampling back down — the styles set a
lot of hairline rules and small mono caps, and those survive the downsample noticeably cleaner.

[CardFacts](../../app/Services/Run/Story/Card/CardFacts.php) is the single reading of the run:
distance at the app's own two decimals, elapsed time, pace, place, the date in the app's
`j M Y` cased up for the mono labels, and the emoji-stripped badge names. Stripping matters —
`Badge::label()` prefixes an emoji emblem, and a colour-emoji glyph through librsvg is not
something the card should depend on.

**Nothing measures text.** librsvg has no layout engine PHP can query, so every variable-length
string is left-anchored at a known x, right-anchored at the opposite edge, or centred in a box.
Where a box has to fit around mono text, [Svg::monoWidth](../../app/Services/Run/Story/Card/Svg.php)
computes it from JetBrains Mono's fixed 600/1000-em advance, which is arithmetic on a font fact
rather than a guess. The broadsheet's Fraunces hero is the one proportional figure, and it steps
down a fixed size ladder by character count instead.

## Styles, forms and aspects

Three styles ship and the athlete picks; none is suggested as a default.

| Style | `style=` | Composition | Rarity escalates by |
|---|---|---|---|
| Broadsheet | `broadsheet` | Editorial poster: masthead rule, one huge Fraunces-italic figure over a ghosted route, ruled stat baseline | Additive chrome: rarity word, edge bar, coloured band, diagonal wedge with a misregistered echo, whole-card wash |
| Ticket | `ticket` | Printed stock: ink band, boxed cells, perforated tear, stub. Mono throughout | Print finishing: band ink, second ruled border, foil strip, second perforation row |
| Topo plate | `topo` | Surveyed map sheet: ticked collar, contour terrain, scale bar, north arrow, ruled title block | Survey density: 7 to 15 contour rings, hypsometric tint, promoted and centre-lined trace, round survey stamp |

Form follows the run. [RunForm](../../app/Services/Run/Story/Card/RunForm.php) resolves to race
(Strava `workout_type` 1), then PR, then long, then no-GPS, then easy, and each style composes
differently per form — a race turns the ticket into a bib, the broadsheet swaps its hero from
distance to finish time, the plate adds a chequered finish gate and a splits row. The route
placeholder is keyed on the polyline rather than the form, so any route-less run gets the
typographic belt, the cancelled window or the `UNSURVEYED` field whatever its form.

Two aspects: `story` at 1080x1920 and `feed` at 1080x1080. A story card holds its content inside
`CardAspect::SAFE_TOP`..`SAFE_BOTTOM`, and the band above and below is the card's own ground — a
deliberate mat rather than dead space, so a story app's chrome covers nothing.

## The endpoint

`GET /activities/{activity}/card.png`, authenticated, 404 on a run the athlete does not own or a
run with no card yet. Query parameters:

- `style` — `broadsheet` / `ticket` / `topo`, or the design round's `a` / `b` / `c` shorthand
- `aspect` — `story` / `feed`
- `hr`, `elevation`, `weather`, `badges` — the optional facts, each defaulting to on

Distance, time, pace, the route or its placeholder, the date, the place and the wordmark are on
every card and have no switch. Temari's narration is never printed on the card; the card-flavor
line rides along as the share sheet's caption instead.

[RunCardImageController](../../app/Http/Controllers/RunCardImageController.php) caches each
tuple, keyed by card id plus the card's `updated_at`, so flipping a fact chip costs one render
per combination rather than one per tap, and a rebuilt card invalidates every variant of itself.
The dead `run_cards.share_image_path` column is deliberately unused: it holds one path and a card
has a tuple of variants.

## See also

- [[cards-collection]] — where the card's rarity, badges and special move come from
- [[telegram-notifications]] — the post-run photo, rendered by the same renderer at style A
- [[design-tokens]] — the palette the card's literal hexes mirror
