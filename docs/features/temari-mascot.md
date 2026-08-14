---
title: Temari mascot
description: The character that voices the app — faces from mood/vibe and equipped gear from shared props
tags: [feature, temari]
status: living
reviewed: 2026-08-14
code_refs:
  - resources/js/components/temari/Temari.tsx
  - resources/js/components/temari/TemariProto.tsx
  - resources/js/lib/temariPose.ts
  - resources/brand/build-mascot.mjs
  - resources/brand/build-accessories.mjs
---

# Temari mascot

Temari is the app's running companion — the same character that narrates every
recap, speech, and insight. The component family lives in
`resources/js/components/temari/`. There are two layers: a pure SVG renderer and
a dressed-up wrapper.

**No dedicated route** — Temari is mounted inline across [[dashboard]], [[run-detail]], [[profile]], [[run-history]], and [[cards-collection]]. Every face, slot and season phase is also rendered live on `/devtools/design` ([Design.tsx](../../resources/js/pages/Devtools/Design.tsx)), against the same stylesheet the token audits read, so the character drifts visibly rather than quietly.

## System dependencies

- **Gamification** — the `equipped` accessories map is written by [[gamification]] during ingest.
- **Vibe & mood** — pose is driven by the daily vibe (`VIBE_TO_POSE`) or run mood (`MOOD_TO_POSE`) from [[vibe-and-mood]].
- **Design tokens** — every fill resolves to a token in [[design-tokens]].
- **Voice** — Temari's speech copy follows [[voice-and-tone]].

## The generator is the source of truth

The character is drawn by [build-mascot.mjs](../../resources/brand/build-mascot.mjs) and its
catalogue by [build-accessories.mjs](../../resources/brand/build-accessories.mjs) — the same
generators that produce the brand SVGs and preview sheets. One geometry produces all ten faces, so
they share a skull; `EYE_Y` is tuned so the face *mass* balances (equal ink above and below the
body centre), which is not the same as centring the eyes, and looks wrong if "corrected" by eye.

[TemariProto.tsx](../../resources/js/components/temari/TemariProto.tsx) is the React port of that
geometry, not a second drawing of it.
[TemariProto.test.tsx](../../resources/js/components/temari/TemariProto.test.tsx) renders each face
and compares every path/circle/ellipse against `mascot(state)` from the generator, pins the 24
catalogue colours against `ITEMS`, and checks the placement transform against the exported
`BOUNDS`. A change to either generator fails the suite instead of silently forking the character.
The generators are reachable from Vitest through the `@brand` alias in
[vitest.config.ts](../../vitest.config.ts), which exists **only** in the test config — nothing in
`resources/brand/` ever reaches a shipped bundle.

### Placement

The mascot is drawn in its own 100-unit space and scaled into the `0 -4 120 140` viewBox every
call site already reserves. The scale and offset are derived from the generator's exported
`BOUNDS`, including `withAccessories` — the widest thing on a *bare* character is the mood halo,
but an equipped aura reaches further on every side, and reading only the halo is what clipped the
top edge on the first attempt. No hand-tuned offsets.

## The ten faces

`resting`, `pleased`, `impressed`, `hyped`, `skeptical`, `unimpressed`, `challenging`,
`concerned`, `disappointed`, `celebrating`. Each is a brow pair, an eye shape
(`open` / `wide` / `lid` / `wink-r`), a mouth, and a **mood halo**: a closed ring around the body
whose colour and stroke weight carry the mood. The halo is never a fill, so it can't be misread as
a progress meter, and its colours come from the mood tokens darkened only where the raw token
misses 3:1 on cream.

**An equipped aura suppresses the mood halo.** The two rings are concentric and, under the heaviest
halo (`gold`, weight 9), leave a 0.3-unit gap — measured in the running app that is 0.11 CSS px at
the 34 px dashboard mini and still only 0.30 CSS px at the 96 px equip preview, so the pair fused
into one thick smear at *every* size rather than reading as two signals. The aura is the earned,
deliberate ring and takes precedence; the ambient mood ring returns the moment the aura comes off,
so no signal is permanently lost. The rule lives in one place per side — `mascot()`'s `showHalo` in
[build-mascot.mjs](../../resources/brand/build-mascot.mjs) and the `aura === null` guard in
[TemariProto.tsx](../../resources/js/components/temari/TemariProto.tsx) — and is proven identical
by the parity block in
[TemariProto.test.tsx](../../resources/js/components/temari/TemariProto.test.tsx), which diffs an
aura-equipped render of every face against `mascot(state, { wearing: ['aura'] })`. Placement is
deliberately *not* re-derived: `BOUNDS` stays the union of the bare halo and the equipped aura, so
equipping an aura never resizes the character.

The eight original pose names (`proud`, `pumped`, `excited`, `holding`, `reading`, `wobble`,
`observational`, `glow`) are still valid `pose` values and resolve to the face carrying the same
read, so [temariPose.ts](../../resources/js/lib/temariPose.ts) and every call site are untouched.
The resolved face is exposed as `data-expression` on the root element.

## The two core components

[TemariProto.tsx](../../resources/js/components/temari/TemariProto.tsx) takes `pose`, `size`,
`tone`, `equipped`, `animate`, `dropShadow`, `seasonPhase` and `className`. It paints the six
equipped slots: `headband` / `shirt` / `shorts` as flat bands clipped to the body circle (so they
take the ball's curve for free and can never escape the silhouette), `shoes` under the body,
`medal` on a lace over it, and `aura` as a dashed ring *in place of* the halo. Colour carries rarity
and a small detail carries the theme, so two rare items in the same slot still read as different
objects — those values were swept for separation and are not re-pickable casually. `tone` selects
the silhouette outline (indigo on cream surfaces, cream on the one sky-panel placement). Motion is
per-pose CSS animation only (`POSE_ANIM`, keyframes in `app.css`), never framer-motion, because the
mascot renders on Login inside the framer-motion-free `bareLayout`. It's `memo`'d with a
field-level comparator so a fresh inline `equipped={{...}}` doesn't rebuild the whole tree.

[Temari.tsx](../../resources/js/components/temari/Temari.tsx) is the **wrapper you almost always
use**. It reads `equippedAccessories` from the globally-shared Inertia props (built in
[GamificationProps.php](../../app/Services/Inertia/GamificationProps.php)), maps them with
`serverToEquipped` ([equippedAccessories.ts](../../resources/js/lib/equippedAccessories.ts)), and
renders `TemariProto`. So a hard-earned headband shows up *everywhere* Temari appears, not just on
the Accessories page. Use `TemariProto` directly only when a *specific* accessory must show (the equip
preview, the just-unlocked celebration). See [[targets-accessories]].

## Season coverage

An optional `seasonPhase` prop (`base`/`build`/`peak`/`taper`) winds discrete thread bands around
the body: `base` (one sparse band) → `build` (three) → `peak` (six, fully wound) → `taper` (peak's
full set plus a rested shine — progress kept, never undone). Only the [[plan-periodizer]] Plan
tab's season summary passes it, so the mascot stays phase-agnostic everywhere else.

## Picking a pose

[temariPose.ts](../../resources/js/lib/temariPose.ts) holds the maps from app
state to pose:

- `MOOD_TO_POSE` — run `Mood` → pose (e.g. `blazing` → `proud`, `gassed` → `wobble`,
  `chill` → `reading`). Used on the run detail and the recaps.
- `VIBE_TO_POSE` — a persona/weekly `vibe` string → pose (e.g. `pumped` →
  `pumped`, `cooked` → `wobble`).
- `poseForFormStatus` — weekly training-load `FormStatus` → pose, used on the
  Jejak weekly recap.

## Size & animation

`Temari` takes `size` (px) and `animate` (`false` = static, `true` =
pose-driven, or an explicit CSS animation string). Recap cards pass
`animate={false}` for a calm static portrait; ambient placements animate.

## See also

- [[design-tokens]] — the palette these SVG fills are tuned to
- [[voice-and-tone]] — what Temari actually *says* in the bubbles
- [[targets-accessories]] — where the equipped gear is earned and chosen
