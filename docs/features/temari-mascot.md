---
title: Temari mascot
description: The character that voices the app — poses from mood/vibe and equipped gear from shared props
tags: [feature, temari]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/components/temari/Temari.tsx
  - resources/js/components/temari/TemariProto.tsx
  - resources/js/lib/temariPose.ts
---

# Temari mascot

Temari is the app's running companion — the same character that narrates every
recap, speech, and insight. The component family lives in
`resources/js/components/temari/`. There are two layers: a pure SVG renderer and
a dressed-up wrapper.

**No dedicated route** — Temari is mounted inline across [[dashboard]], [[run-detail]], [[profile]], [[run-history]], and [[cards-collection]].

## System dependencies

- **Gamification** — the `equipped` accessories map is written by [[gamification]] during ingest.
- **Vibe & mood** — pose is driven by the daily vibe (`VIBE_TO_POSE`) or run mood (`MOOD_TO_POSE`) from [[vibe-and-mood]].
- **Design tokens** — all SVG fills are tuned to the Daybreak palette in [[design-tokens]].
- **Voice** — Temari's speech copy follows [[voice-and-tone]].

## The two core components

[TemariProto.tsx](../../resources/js/components/temari/TemariProto.tsx) is the
hand-drawn SVG body — a single ~950-line component. Its `pose` prop (the
`TemariPose` union: `proud`, `pumped`, `excited`, `holding`, `reading`,
`wobble`, `observational`, `glow`) drives ear tilt, eye shape, mouth, arm swing,
and a per-pose CSS animation (`POSE_ANIM`). It also paints equipped gear from an
`equipped` object: headband, medal, kaus, celana, sepatu, aura — each keyed into
its own palette table. `holding`/`reading` poses grip a book; `pumped`/`excited`/
`glow` (and any aura) add sparkles. It's `memo`'d with a field-level comparator
so a fresh inline `equipped={{...}}` doesn't rebuild the whole tree.

[Temari.tsx](../../resources/js/components/temari/Temari.tsx) is the **wrapper you
almost always use**. It reads `equippedAccessories` from the globally-shared
Inertia props (built in
[SharedProps.php](../../app/Services/Inertia/SharedProps.php)),
maps them with `serverToEquipped`
([equippedAccessories.ts](../../resources/js/lib/equippedAccessories.ts)), and
renders `TemariProto`. So a hard-earned headband shows up *everywhere* Temari
appears, not just on the Aksesori page. Use `TemariProto` directly only when a
*specific* accessory must show (the equip preview, the just-unlocked
celebration). See [[targets-accessories]].

## Picking a pose

[temariPose.ts](../../resources/js/lib/temariPose.ts) holds the maps from app
state to pose:

- `MOOD_TO_POSE` — run `Mood` → pose (e.g. `nyala` → `proud`, `lemes` → `wobble`,
  `adem` → `reading`). Used on the run detail and the recaps.
- `VIBE_TO_POSE` — a persona/weekly `vibe` string → pose (e.g. `pumped` →
  `pumped`, `cooked` → `wobble`).
- `poseForFormStatus` — weekly training-load `FormStatus` → pose, used on the
  Jejak weekly recap.

## Size & animation

`Temari` takes `size` (px) and `animate` (`false` = static, `true` =
pose-driven, or an explicit CSS animation string). Recap cards pass
`animate={false}` for a calm static portrait; ambient placements animate.

## See also

- [[design-tokens]] — the Daybreak palette these SVG fills are tuned to
- [[voice-and-tone]] — what Temari actually *says* in the bubbles
- [[targets-accessories]] — where the equipped gear is earned and chosen
