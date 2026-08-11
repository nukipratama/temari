---
title: Rebrand Temari from bunny/Daybreak to a thread-ball character
description: Full character replacement (bunny → ball-bodied thread character) and palette rename (Daybreak → Threadwork), tying the visual identity to the training arc; rarity hex and the accessory data model stay unchanged.
tags: [decision, design]
status: accepted
reviewed: 2026-08-11
code_refs:
  - resources/js/components/temari/TemariProto.tsx
  - resources/js/components/BrandMark.tsx
  - resources/js/lib/shareCard.ts
  - app/Enums/Rarity.php
  - resources/css/app.css
  - app/Services/Gamification/EquippedAccessories.php
---

# Rebrand Temari from bunny/Daybreak to a thread-ball character

**Status:** Accepted (documented 2026-08-11, resolved in a full `/grilling` session 2026-08-10/11).

## Context

Temari shipped its whole life as a bunny character on a "Daybreak" palette (`app.css`'s literal framing: pre-dawn Jakarta at 05:30). Neither had any connection to the app's own domain model. By the time of this decision the app had grown a real training arc — a periodized season with `base`/`build`/`peak`/`taper` phases (Slice 6/7) and a badge/rarity ladder (Slice 4/7) — and the bunny form had no way to express either. The identity was arbitrary where the app now has structure worth showing.

Nothing in the prior codebase or docs referenced the temari (手鞠) etymology — a traditional Japanese hand-wound thread ball, built up thread-by-thread over time, historically gifted between friends. Adopting it here is a new deliberate choice, not a rediscovery: it gives the character a literal, thread-by-thread growth mechanic that maps directly onto the season's progress, which the bunny form never had.

## Decision

- **Full character replacement, not a reskin.** The bunny form ([TemariProto.tsx](../../resources/js/components/temari/TemariProto.tsx), `BrandMark.tsx`'s `BunnyGlyph`, `shareCard.ts`'s `bunnySvg()`/`loadBunny()`) retired in favor of a ball-bodied character with a face (eyes/mouth on the ball's surface) — chosen over a limbed thread-wrapped hybrid or a faceless abstract icon so the "friend who runs alongside you" persona keeps an expression/warmth carrier in narration UI. It moves by bouncing/rolling, not walking. The same 8 pose names carry over unchanged (`proud`/`pumped`/`excited`/`holding`/`reading`/`wobble`/`observational`/`glow`, [TemariProto.tsx:5-13](../../resources/js/components/temari/TemariProto.tsx#L5-L13)) since narrators and components across the app reference them by name; renaming would have been unrelated churn. `BunnyGlyph`/`bunnySvg`/`loadBunny` ported to `TemariGlyph`/`temariSvg`/`loadTemari` ([BrandMark.tsx:35](../../resources/js/components/BrandMark.tsx#L35), [shareCard.ts:397](../../resources/js/lib/shareCard.ts#L397), [shareCard.ts:418](../../resources/js/lib/shareCard.ts#L418)).
- **The training arc gets a real rendering tie-in, scoped to one site.** Thread coverage over the ball's core builds through discrete season-phase states (`base`/`build`/`peak`/`taper`), rendered only in the Plan tab's season summary — not globally, since a phase-aware mascot everywhere would require every call site to track the current phase. Self-scaled deload weeks pause accretion rather than reset it, matching the season's own self-scaling design.
- **Accessory rendering changed, the data model didn't.** The 6-slot enum, 25-item unlock catalog, and `EquippedAccessories`'s schema stay exactly as Slice 2f/4 left them ([EquippedAccessories.php:26](../../app/Services/Gamification/EquippedAccessories.php#L26)) — no new migration. Only `TemariProto.tsx`'s per-slot SVG rendering changed to fit a limbless ball: `medal` hangs from a loop at the crown, `aura` is an ambient glow ring, `headband` is a ribbon bow at the crown, `shirt`/`shorts` are thread-band wraps around the ball's upper/lower hemisphere, and `shoes` is a trailing ribbon suggesting motion.
- **Rarity's hex ladder stays exactly as-is.** `Rarity::hexColor()` (gray→green→blue→purple→gold, [Rarity.php:46](../../app/Enums/Rarity.php#L46)) was never re-hued — re-hueing a proven, widely-used loot-ladder signal (filter chips, dots, section headers) is separate blast radius this decision didn't ask for. Card chrome instead gained an additive thread-band accent whose density scales with tier, layered on the unchanged rarity colors.
- **The palette renamed Daybreak → Threadwork** (jewel-tone thread family — crimson/gold/indigo/emerald/violet — on a warm linen canvas, replacing the sky/horizon/cream "pre-dawn Jakarta" framing). Token *identifiers* stayed stable; only hex values and naming/comments changed, so existing Tailwind call sites repainted for free. See [design-tokens.md](../design-tokens.md).
- **The marketing intro video was removed outright**, not just left unreferenced — `docs/marketing/`, `public/videos/intro.mp4`, and its poster image are gone, since they depicted the retired bunny identity and re-shooting was out of scope. The Login page's video hero was replaced with a static render of the new mascot rather than left blank.

## Consequences

- **Enables:** a mascot that visually reflects the app's own training-arc and rarity systems instead of an arbitrary skin; one coherent jewel-tone palette instead of a sky/dawn metaphor unrelated to running or thread; a cheaper story for future accessory art (thread bands compose naturally, a limbed wardrobe didn't).
- **Costs:** every surface that renders the mascot, a brand mark, or a share card needed a coordinated pass in the same window (Slices 9a/9b/9d merged first; 9c's rarity/card-chrome thread-band accent lands after, rebasing onto the ported `shareCard.ts`). The retired intro video's Remotion source was never committed and is not preserved by this change.
- **Not done:** no accessory slot/catalog rename (rendering-only, per the accessory rendering-vs-data-model split above), no rarity re-hue, no dark-mode variant (the app stays light-mode only).

## See also

- [[voice-and-tone]] — the character section describing the thread-ball's visual mechanics for narration/UI writers.
- [[design-tokens]] — the Threadwork token reference.
