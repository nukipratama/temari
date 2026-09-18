---
title: Share card — one browser renderer, three print styles
description: The card a run is shared as. One SVG renderer in the browser, three print styles the athlete picks, five run forms, two aspects, no server round trip.
tags: [feature, cards]
status: living
reviewed: 2026-09-18
code_refs:
  - resources/js/lib/card/print.ts
  - resources/js/lib/card/svg.ts
  - app/Services/Run/Story/Card/CardFacts.php
  - resources/js/components/card/ShareCardModal.tsx
---

# Share card — one browser renderer, three print styles

A run's card leaves the app as a PNG the browser makes: the print in the share popup, the file the
athlete downloads or copies, and the file the native share sheet hands on are all the same blob.
The server renderer that used to own this retired with `#981` — it was too slow on first open and
on every switch, and it loaded a four-core box shared with production and CI. There is still
exactly one renderer, and it now runs where the athlete is.

## The renderer

[print.ts](../../resources/js/lib/card/print.ts) composes the chosen style's SVG at the aspect's
exact pixel size, loads it into an `Image`, draws it onto a canvas and reads a PNG blob back out.
Everything the file references is inline — including the three font faces, embedded as data URLs,
because an SVG loaded through an `<img>` renders in its own document and cannot see the page's web
fonts. Nothing external means the canvas is never tainted, so `toBlob` works.

The draw waits on `document.fonts.ready` first: the styles fit their boxes by measuring, and
measuring before the faces load silently measures a fallback.

**Text is measured, not guessed.** [svg.ts](../../resources/js/lib/card/svg.ts) keeps a canvas
context for `measureText` and caches each face's advance at font-size 1, so `fitSize` steps a
figure down to exactly the room it has, `truncate` clips a label to a pixel budget, and
`joinWithin` drops a whole badge rather than half a name. The mono boxes stay exact, as they were
when they were arithmetic on JetBrains Mono's fixed advance.

[CardFacts](../../app/Services/Run/Story/Card/CardFacts.php) stays on the server and is the single
reading of the run: distance at the app's own two decimals, elapsed time, pace, place, the date in
the app's `j M Y` cased up for the mono labels, and the emoji-stripped badge names. It ships with
the run page as `card.facts`, with *every* optional fact resolved — the chips live in the browser,
and dropping a fact the client can already see would cost a request to get it back.

## Styles, forms and aspects

Three styles ship and the athlete picks; none is suggested as a default.

| Style | Composition | Rarity escalates by |
|---|---|---|
| Broadsheet | Editorial poster: masthead rule, one huge Fraunces-italic figure over a ghosted route, ruled stat baseline | Additive chrome: rarity word, edge bar, coloured band, diagonal wedge with a misregistered echo, whole-card wash |
| Ticket | Printed stock: ink band, boxed cells, perforated tear, stub. Mono throughout | Print finishing: band ink, second ruled border, foil strip, second perforation row |
| Topo plate | Surveyed map sheet: ticked collar, contour terrain, scale bar, north arrow, ruled title block | Survey density: 7 to 15 contour rings, hypsometric tint, promoted and centre-lined trace, round survey stamp |

Form follows the run. [RunForm](../../app/Services/Run/Story/Card/RunForm.php) resolves to race
(Strava `workout_type` 1), then PR, then long, then no-GPS, then easy, and each style composes
differently per form — a race turns the ticket into a bib, the broadsheet swaps its hero from
distance to finish time, the plate adds a chequered finish gate and a splits row. The route
placeholder is keyed on the polyline rather than the form, so any route-less run gets the
typographic belt, the cancelled window or the `UNSURVEYED` field whatever its form.

Two aspects: `story` at 1080x1920 and `feed` at 1080x1080. A story card holds its content inside
`SAFE_TOP`..`SAFE_BOTTOM` ([types.ts](../../resources/js/lib/card/types.ts)), and the band above
and below is the card's own ground — a deliberate mat rather than dead space, so a story app's
chrome covers nothing.

The exported image is fixed-palette: every value in
[palette.ts](../../resources/js/lib/card/palette.ts) is the literal light-ground token, because a
shared image has no ground to follow. Only the popup's own chrome is ground-reactive.

## The popup

[ShareCardModal](../../resources/js/components/card/ShareCardModal.tsx) is a bottom sheet at phone
width and a centred 480px dialog from 900px up. The story/feed toggle sits above the print; the
three prints sit side by side in a clipped carousel with their neighbours peeking, swipeable and
walkable with the arrow keys; a segmented strip under them names the styles; icon chips toggle the
optional facts, and a chip for a fact the run lacks is hidden rather than disabled. Three equal
actions follow: share (the PNG as a file, falling back to the run's URL), copy image, download.

In story mode a faint scrim marks the bands a story app's own UI covers. It is drawn over the
print by the popup and never by the renderer, so it cannot reach the exported file.

[useCardPrints](../../resources/js/components/card/useCardPrints.ts) draws the style on screen
first and its neighbours after, and memoises every result by `{style, aspect, facts}`. Switching
back to a tuple already drawn is a map lookup, so no progress is shown for it at all; a genuinely
new one keeps the previous print on screen under a bar, and only a first draw with nothing to show
gets a skeleton. A draw that fails offers a retry.

Distance, time, pace, the route or its placeholder, the date, the place and the wordmark are on
every card and have no switch. Temari's narration is never printed on the card; the card-flavor
line rides along as the share sheet's caption instead.

## See also

- [[cards-collection]] — where the card's rarity, badges and special move come from
- [[telegram-notifications]] — the post-run message, which links to the run rather than attaching a print
- [[design-tokens]] — the palette the card's literal hexes mirror
