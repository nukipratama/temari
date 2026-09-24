---
title: Temari's face
description: The living brand mark (the logo's two arcs posed per mood), how each surface presents it, and the fixed brand mark itself.
tags: [feature, temari]
status: living
reviewed: 2026-09-24
code_refs:
  - resources/js/components/temari/TemariMascot.tsx
  - resources/js/components/temari/MascotPeek.tsx
  - resources/js/components/TemariMark.tsx
  - resources/js/components/HeaderBrandMark.tsx
---

# Temari's face

Temari is the app's running companion, the same character that narrates every recap, speech and
insight. It is drawn as the **living brand mark**: the logo's two nested arcs, posed to a mood,
with a small face inside. Why this replaced the one-smile `FaceIcon` is recorded in
[[mascot-is-the-living-brand-mark]].

**No dedicated route.** Every pose, size, motion and presentation mode is rendered live on
`/devtools/design` ([Design.tsx](../../resources/js/pages/Devtools/Design.tsx)) on both the page
ground and sky.

## System dependencies

- **Design tokens.** Arcs and features resolve to `--color-*` tokens in [[design-tokens]]: the outer arc on `horizon`, the inner on the mood's `-ink` tier (blazing keeps the vivid gold), features on `foreground`.
- **Vibe & mood.** A run `Mood` picks the pose on run surfaces; the daily vibe does on Today and Profile, via `moodForVibe`. See [[vibe-and-mood]].
- **AI pipeline.** A card whose narration is `queued`/`processing` switches its mascot to `thinking` (`writingPose`). See [[ai-pipeline]].
- **Voice.** What Temari says follows [[voice-and-tone]].

## TemariMascot

[TemariMascot.tsx](../../resources/js/components/temari/TemariMascot.tsx) holds a `POSES` table:
per pose, the outer and inner arc spans, an optional tilt, the inner colour, and the face (eyes,
brows, mouth). The poses are the six run moods plus `neutral`, `sleepy` (dotted outer arc) and
`thinking` (short counter-rotating arcs).

| prop | what it does |
|---|---|
| `pose` | which entry of `POSES` to draw |
| `size` | both axes; the face drops to eyes only when it would render under 32px |
| `onSky` | scopes `data-theme="dark"` onto the svg so a fixed-dark surface resolves dark-ground tokens |
| `drawIn` | traces the arcs in once on mount (reuses `.draw-in`); off under reduced motion |
| `faceOnly` | just the face, cropped to fill the box, for a slot another ring already frames |

`writingPose(pose, ...blocks)` returns `thinking` while any block is being written.

## Presentation modes

A page carries **at most one corner peek**; every other placement uses a lighter mode.

| mode | what it is | surfaces |
|---|---|---|
| Corner peek | [MascotPeek](../../resources/js/components/temari/MascotPeek.tsx), cropped by the card's top-left corner. `MascotPeekClearance` floats a spacer so copy wraps round it | RunHero, ProfileHero (`panel`, 112px); TodaySession, the Feed's newest recap, the Calendar's monthly recap (`card`, 96px) |
| Gutter tag | 28px beside the voice line | RunLenses header, later recaps, NoPlanCard, Race projection |
| Sleepy inline | the `sleepy` pose beside the copy | EmptyPanel, EmptyRunsState (`thinking` while a sync runs) |
| Hero + draw-in | 72px, traced in once | Onboarding's connected step, TemariNudgeModal |
| Face only | the face as the centre of a ring | Onboarding's required-pace ring |
| Thinking mark | 24px `thinking` beside AnalysisStatus's skeleton, opt-in via `thinkingMark` | Plan's TemariTake, the calendar's weekly recap |

It is **absent from Login, Trends and Settings**, which must not gain one.

## TemariMark

[TemariMark.tsx](../../resources/js/components/TemariMark.tsx) is the brand mark: two nested open
arcs, the outer always on `--color-horizon`, the inner following a `color` prop. The mascot is
built from its geometry, but the mark itself **never reacts** to mood. It is drawn by
[HeaderBrandMark](../../resources/js/components/HeaderBrandMark.tsx),
[BrandMark](../../resources/js/components/BrandMark.tsx) on Login,
[RouteGlyph](../../resources/js/components/card/RouteGlyph.tsx), and the share card (see
[[share-card]]).

## See also

- [[mascot-is-the-living-brand-mark]]: the decision and what was rejected
- [[design-tokens]]: the palette these strokes resolve through
- [[voice-and-tone]]: what Temari actually *says*
