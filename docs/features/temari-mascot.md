---
title: Temari mascot
description: The character that voices the app — poses from mood/vibe, equipped gear from shared props, and bubble/peek/thread variants
tags: [feature, temari]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/components/temari/Temari.tsx
  - resources/js/components/temari/TemariProto.tsx
  - resources/js/lib/temariPose.ts
  - resources/js/components/temari/TemariBubble.tsx
  - resources/js/components/temari/TemariThread.tsx
  - resources/js/components/temari/TemariLottie.tsx
  - resources/js/components/temari/TemariPeek.tsx
---

# Temari mascot

Temari is the app's running companion — the same character that narrates every
recap, speech, and insight. The component family lives in
`resources/js/components/temari/`. There are two layers: a pure SVG renderer and
a dressed-up wrapper, plus a few presentational variants.

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
Inertia props (set in
[HandleInertiaRequests.php](../../app/Http/Middleware/HandleInertiaRequests.php)),
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
  `adem` → `reading`). Used on the run detail, recaps, bubbles, threads.
- `VIBE_TO_POSE` — a persona/weekly `vibe` string → pose (e.g. `pumped` →
  `pumped`, `cooked` → `wobble`).
- `poseForFormStatus` — weekly training-load `FormStatus` → pose, used on the
  Jejak weekly recap.

## Variants

- [TemariBubble](../../resources/js/components/temari/TemariBubble.tsx) — mascot
  beside a single speech bubble (an `AnalysisStatus` row), `sm`/`lg` sizes,
  pose from the line's mood.
- [TemariThread](../../resources/js/components/temari/TemariThread.tsx) — the
  mascot anchoring a vertical chat-style thread of several analyses connected by
  a rail; collapses gracefully to one entry. Used for multi-lens narration.
- [TemariLottie](../../resources/js/components/temari/TemariLottie.tsx) — fetches
  a Lottie JSON by `src` and plays it via a lazy `LottiePlayer`; **falls back to
  the SVG `Temari`** whenever `src` is empty or the fetch fails, so no rigged
  asset is required to ship.
- [TemariPeek](../../resources/js/components/temari/TemariPeek.tsx) — an ambient
  tooltip-style peek that pops a random line once per session (sessionStorage
  guard), respecting `prefers-reduced-motion`; the parent must be `relative`.

## Size & animation

Every variant takes `size` (px) and `animate` (`false` = static, `true` =
pose-driven, or an explicit CSS animation string). Recap cards pass
`animate={false}` for a calm static portrait; ambient placements animate.

## See also

- [[design-tokens]] — the Daybreak palette these SVG fills are tuned to
- [[voice-and-tone]] — what Temari actually *says* in the bubbles
- [[targets-accessories]] — where the equipped gear is earned and chosen
