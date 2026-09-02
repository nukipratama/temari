---
title: Temari's face
description: The line-art face and the brand mark — the app's whole identity art since PP2 retired the mascot rig.
tags: [feature, temari]
status: living
reviewed: 2026-08-31
code_refs:
  - resources/js/components/temari/FaceIcon.tsx
  - resources/js/components/TemariMark.tsx
  - resources/js/components/HeaderBrandMark.tsx
---

# Temari's face

Temari is the app's running companion — the same character that narrates every recap, speech and
insight. Its whole drawn identity is now **two flat SVGs**: a face and a brand mark.

**The elaborate mascot rig is gone.** Until `PP2` the character was a ball-bodied figure with ten
drawn expressions, a mood halo, six wearable accessory slots and a season thread-coverage overlay,
ported from `resources/brand/build-mascot.mjs` into `TemariProto.tsx` and pinned against that
generator. The frozen prototype draws none of it — only a simple ring-and-face icon — so decision
P10 cut the rig, its pose vocabulary (`temariPose.ts`), the accessory-driven variants
(`Temari.tsx`, `lib/equippedAccessories.ts`) and the page that dressed it (see
[[targets-accessories]]). Git history holds all of it. The generators themselves
(`build-mascot.mjs` and the four brand scripts that imported it) were exploration art with no app
consumer left, and `W2` swept them along with the rest of the unread brand preview layer.

**No dedicated route** — both marks are mounted inline. Every size the face ships at is rendered
live on `/devtools/design` ([Design.tsx](../../resources/js/pages/Devtools/Design.tsx)), against
the same stylesheet the token audits read, so the art drifts visibly rather than quietly.

## System dependencies

- **Design tokens** — every stroke and fill resolves to a `--color-*` token in [[design-tokens]],
  including the two grounds. See below.
- **Vibe & mood** — a mood no longer picks a pose; it picks the face's *ring colour* on the
  surfaces that carry one. See [[vibe-and-mood]].
- **Voice** — Temari's speech copy follows [[voice-and-tone]].

## FaceIcon

[FaceIcon.tsx](../../resources/js/components/temari/FaceIcon.tsx) is a direct port of the
prototype's own `FaceIcon`: a 100-unit viewBox holding an open ring (r 41), a filled face disc
(r 31), two brows, two eyes and a smile. One `size` prop drives both axes.

Three colours are separate props so a surface can tint one without touching the others:

| prop | default | what it is |
|---|---|---|
| `ring` | `--color-horizon` | the outer ring. Mood-tinted where the surface carries a mood, leaf on Today's and Profile's hero cards, brand horizon everywhere else |
| `fill` | `--color-card` | the face disc |
| `feature` | `--color-foreground` | brows, eyes, mouth |

The defaults are the ground-reactive semantic tokens, so the face inverts with the app's ground for
free. `DARK_FACE` (exported alongside) is the prototype's own inverted read — `--color-sky-2` disc,
`--color-cream` features — used where the surface is fixed-dark regardless of ground, such as the
recap cards.

### Where it is drawn

Eight of the prototype's eleven screens, at the size each one uses. It is **absent from Login,
Trends and Settings**, which must not gain one.

| screen | placement | size |
|---|---|---|
| Today | `TodaySession`'s header (leaf ring) | 42 |
| Today | `NoPlanCard`, when the account has no plan yet | 40 |
| Plan · Race · Inbox · Today · History | the shared `EmptyPanel` empty state | 48 |
| History | `RecapCard`, ringed by the week's mood | 36 |
| History | `EmptyRunsState`'s hero | 72 |
| Activity | the run hero panel | 56 |
| Activity | the "what Temari says" header | 40 |
| Profile | the hero panel (leaf ring) | 64 |
| Onboarding | the connected step | 72 |
| Onboarding | the centre of the required-pace ring | 26 |

The nudge-modal shell ([TemariNudgeModal](../../resources/js/components/temari/TemariNudgeModal.tsx))
also draws one at 72; it is real-app plumbing the static prototype has no equivalent for (P1).

## TemariMark

[TemariMark.tsx](../../resources/js/components/TemariMark.tsx) is the brand mark — two nested open
arcs, the outer one always on `--color-horizon`, the inner following a `color` prop. Ported from
the prototype's `rack/TemariMark.tsx`, the only logo it draws.

It is the app's **single** brand mark since `PP2`. Before, there were two: this ring glyph in the
shell header, and a second mascot-face glyph (`BrandMark`'s `TemariGlyph`) everywhere else. Four
surfaces draw it now:

- [HeaderBrandMark](../../resources/js/components/HeaderBrandMark.tsx) — the shell header lockup.
- [BrandMark](../../resources/js/components/BrandMark.tsx) — the larger wordmark lockup, on Login.
- [RouteGlyph](../../resources/js/components/card/RouteGlyph.tsx) — a Kartu's watermark when a run
  has no route to draw, and `KartuMini`'s corner signature.
- The share card, hand-ported to canvas as `brandMarkSvg` in
  [shareCard.ts](../../resources/js/lib/shareCard.ts) — see [[cards-collection]].

## See also

- [[design-tokens]] — the palette these SVG strokes resolve through
- [[voice-and-tone]] — what Temari actually *says*
- [[targets-accessories]] — the accessory catalog the rig used to wear
