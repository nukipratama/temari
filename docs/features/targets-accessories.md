---
title: Targets & accessories (Target / Aksesori)
description: The goals page (progress per equipment slot) and the accessories page (equip/unequip with a live Temari preview).
tags: [feature, collection]
status: living
reviewed: 2026-06-20
code_refs:
  - resources/js/pages/Target.tsx
  - resources/js/pages/Koleksi/Aksesori.tsx
  - app/Http/Controllers/GoalController.php
  - app/Http/Controllers/AksesoriController.php
  - resources/js/components/temari/TemariProto.tsx
  - resources/js/components/celebrations/AksesoriUnlockModal.tsx
---

# Targets & accessories (Target / Aksesori)

Two sides of the same loop: **Target** (`/target`) shows what you're working toward, and **Aksesori** (`/aksesori`) is the wardrobe of what you've earned and can put on Temari. Both are organized by the same six equipment **slots**: Medali, Ikat Kepala, Kaus, Celana, Sepatu, Aura.

## Targets (`/target`)

The [GoalController](../../app/Http/Controllers/GoalController.php) `index` delegates to the `GoalResolver` service, which returns the user's goals (each with `current` / `target` / `unit`, a `slot`, a `rarity`, and an `is_completed` flag) plus a completed count. The page [Target](../../resources/js/pages/Target.tsx) groups goals by slot in a fixed order, and renders each as a `GoalCard` with a progress bar driven by `goalProgressRatio`. A completed goal flips to a horizon accent with a check chip; the header shows the running "N / total tercapai" tally. The page is read-only — progress is recomputed server-side from run data, not edited here.

## Accessories (`/aksesori`)

The [AksesoriController](../../app/Http/Controllers/AksesoriController.php) `index` walks the `temari_unlocks` config catalog and, for each entry, resolves its slot (via the `EquippedAccessories` service), whether the user has unlocked it (`UserUnlock` rows), and whether it's currently equipped. It also returns the resolved `equipped` map (one key per slot).

[KoleksiAksesori](../../resources/js/pages/Koleksi/Aksesori.tsx) renders:

- A **live preview hero** — the currently-equipped set mapped onto [TemariProto](../../resources/js/components/temari/TemariProto.tsx), the mascot rig that actually draws each accessory (headband / medal / kaus / celana / sepatu / aura). The "Yang lagi dipake" list mirrors what each slot holds.
- **Per-slot sections** — unlocked items first, locked items dashed-out with a lock badge and their unlock criteria. On mobile the locked items collapse behind a "+N belum kebuka" toggle; on `sm+` they're always shown. Each unlocked, un-equipped item shows a **Pasang** button.

### Equipping

**Pasang** posts to `/api/aksesori/equip` with the `unlock_key` (`preserveScroll`). The controller's `equip` method validates the key is unlocked and slotted, then **unequips every sibling in the same slot** before marking this one equipped — so a slot holds at most one item. It redirects back, and Inertia re-renders with the new `equipped` map, so the preview Temari updates immediately.

### Unlock celebration

When a run earns a *major* accessory, [AksesoriUnlockModal](../../resources/js/components/celebrations/AksesoriUnlockModal.tsx) (mounted globally) pops with Temari wearing the new item and a CTA that routes to `/aksesori`. It only opens when the unlock flash carries `is_major`. The unlock itself is granted upstream during ingest — see [[gamification]].

## Notes

- The slot system, accessory rig, and poses live with the mascot — see [[temari-mascot]].
- Unlock and goal state are stored in `user_unlocks` and resolved goals; see [[data-model]].
