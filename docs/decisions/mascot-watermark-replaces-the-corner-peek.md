---
title: A watermark replaces the mascot's corner peek
description: Temari's big presentation is a faint watermark bleeding off a card's edge behind the content, on every hero and every recap card, placed per card; the small gutter tag grows to a 40px full face.
tags: [decision, design]
status: accepted
reviewed: 2026-09-24
code_refs:
  - resources/js/components/temari/MascotWatermark.tsx
  - resources/js/components/temari/TemariMascot.tsx
---

# A watermark replaces the mascot's corner peek

## Context

[[mascot-is-the-living-brand-mark]] put Temari in a card's top-left corner as a 96–112px **corner peek**, with a float that made copy wrap round her, at most once per page. Everywhere else she was a 28px **gutter tag**. The owner's review in prod (#1142) found three faults:

- The peek read as a small, half-cut blob, not a presence.
- The clearance float cost space. It left a dead gap beside Today's one-word eyebrow and stretched the Calendar's one-line recap card.
- The one-per-page rule chose badly in the Feed. The peek landed on the ongoing week, whose recap wasn't written yet, while the written past weeks got the small tag.

The 28px eyes-only tag also read as a smudge.

## Decision

The options were rendered as pictures on both grounds, and the owner picked these:

- **Watermark (A1).** [MascotWatermark](resources/js/components/temari/MascotWatermark.tsx) draws the whole posed mascot at 200px. It is faint, bleeds off the card's edge behind the content, and needs no clearance, so copy runs the full width.
- **Placed per card.** Each surface puts it wherever its own content leaves the face readable, not in one fixed corner. A fixed bottom-right corner was tried first: on real content it landed behind Today's session box, Run's weather box and Profile's stat tiles. Today, Run and Profile sit top-right (Profile moves into the header's open middle from 900px, beside "with temari since"); the recap cards sit bottom-right, where their voice text ends.
- **Where.** RunHero, ProfileHero, TodaySession and **every** recap card (Feed and Calendar, ongoing and past weeks). The one-peek-per-page rule is dropped, because a watermark is quiet enough to repeat down a list.
- **Small tag (B2).** It grows from 28px to 40px, above the eyes-only cut, so the face renders. The same applies to AnalysisStatus's `thinkingMark`.

Everything else in [[mascot-is-the-living-brand-mark]] stands: the poses, what the mascot reacts to, one Temari per card, and a logo that never reacts.

## Rejected

- **Right-edge lean with the face at 45% (A2).** Livelier, but a readable face behind body text competes with it.
- **Huge arcs, no face (A3).** Pure brand, but it loses the "someone is here" cue that the arcs-with-a-face design exists for.
- **A medium, stronger mark fully inside the card (A4).** Reads as a sticker, not a background.
- **A watermark only on the newest written recap.** Consistent in rule but not in look: one card in the list would differ for no reason the reader can see.
- **Mini logo without a face, a speaker chip, or no tag at all (B1, B3, B4)** for the small tag.

## Consequences

- `MascotPeek`, `MascotPeekClearance` and `PANEL_PEEK_CLEARANCE` go away, along with the Feed's choice of a single recap to peek.
- A watermarked card must be `relative isolate overflow-hidden`, so the watermark's `-z-10` sits above the card's background and below its content.
