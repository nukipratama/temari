---
title: The mascot is the living brand mark
description: Temari is drawn as the logo's two arcs posed per mood, replacing the one-smile FaceIcon; one mascot per card, a corner peek at most once per page, and the logo itself never reacts.
tags: [decision, design]
status: accepted
reviewed: 2026-09-24
code_refs:
  - resources/js/components/temari/TemariMascot.tsx
  - resources/js/components/temari/MascotPeek.tsx
  - resources/js/components/TemariMark.tsx
  - app/Http/Controllers/ProfileController.php
---

# The mascot is the living brand mark

## Context

After `PP2` the character was one flat `FaceIcon`: a ring, a face disc and a fixed smile, drawn on about ten surfaces at 18–72px. It smiled the same way after a PR and after a gassed run, since a mood could only tint its ring, and only RecapCard did even that. It read as a generic emoji next to the Pewter, Fraunces and mono-telemetry UI, and it shared nothing with the brand mark, two nested open arcs. Taking a column on every card also made it read as wallpaper rather than a presence. The redesign was grilled on #1128 and shipped as the #1128–#1127 stack.

## Decision

- **The logo's arcs are the character.** [TemariMascot](resources/js/components/temari/TemariMascot.tsx) draws [TemariMark](resources/js/components/TemariMark.tsx)'s two arcs posed per run mood (arc sweep, gap and tilt as body language, never data), plus `neutral`, `sleepy` for empty states and `thinking` for narration being written. A face sits inside and drops to eyes only when it would render under 32px.
- **What it reacts to.** Run surfaces use the run's mood. Today and Profile use the daily vibe collapsed onto a mood by `Temari::moodForVibe`. Any card whose narration is queued or processing switches its own mascot to `thinking`.
- **The logo never reacts.** The header, Login and share-card mark stay fixed.
- **Presentation replaces the column.** A **corner peek** ([MascotPeek](resources/js/components/temari/MascotPeek.tsx)) is cropped by the card's top-left corner, with copy wrapping round it, **at most once per page**. Elsewhere a 28px **gutter tag** sits beside the voice line, a **sleepy** pose sits inline in empty states, and a one-shot **draw-in** marks the big moments. Trends, Login and Settings still carry no mascot.
- **One Temari per card.** A card never shows a second mascot inside its own loading state.
- **Colour.** The inner arc takes the mood's `-ink` tier so it holds contrast on both grounds; blazing keeps the vivid gold, which already clears the dark ground. Fixed-dark surfaces scope `data-theme="dark"` onto the svg.

## Rejected

- **A thread ball with no face.** Literal to the name, but it drops the "someone is here" cue.
- **A mono race-bib stamp.** The most telemetry-native option, and the least warm.
- **Arcs that encode data** (week progress, form). Unreadable at tag sizes, and easy to mistake for a real progress ring.
- **A header logo that follows the vibe.** Brand recognition matters on share cards and Login.

## Consequences

- `FaceIcon` and `DARK_FACE` are deleted.
- Profile gains a lazy `mood` prop, one extra `Vibe::current` read per load.
- The pace ring's centre shows only the face, cropped to fill its slot.
