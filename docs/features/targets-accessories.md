---
title: Accessories
description: The accessories page — equip/unequip with a live Temari preview, live unlock progress toward what's still locked.
tags: [feature, collection]
status: living
reviewed: 2026-08-19
code_refs:
  - resources/js/pages/Collection/Accessories.tsx
  - app/Http/Controllers/AccessoryController.php
  - resources/js/components/temari/TemariProto.tsx
  - resources/js/components/celebrations/AccessoryUnlockModal.tsx
---

# Accessories

**Accessories** (`/accessories`) is the wardrobe of what's been earned and can be put on Temari, showing live progress toward what's still locked. Accessories are organized by six equipment **slots**: medal, headband, shirt, shorts, shoes, aura.

The badge board that used to sit alongside this page as a second Collection sub-tab (`/badges`) retired once its content moved onto `/trends` as badge milestones on the Fitness/Fatigue timeline — see [[gamification]]. `/cards` retired the same way once every earned card became browsable inline on [[run-history]] instead, so Accessories is now the sole Collection page and renders with no tab-switcher chrome above it (the bottom-nav "Collection" tab links straight here) — see [[cards-collection]].

**Navigation:** `route('accessories')` → `/accessories` (`AccessoryController::index`). The old `/goals` accessory-progress page (Slice 5 through Slice 6) and its `/target` legacy redirect both retired in Slice 7 — both now redirect straight to `/accessories`, where the progress numbers moved.

## System dependencies

- **Gamification** — accessory progress and grants come from `GoalResolver`/`GrantEligibleUnlocksAction`; the rest-day reward from `GrantSeasonUnlocksAction`. See [[gamification]].
- **Temari mascot** — the live preview hero uses `TemariProto` to render equipped gear; see [[temari-mascot]].
- **Data model** — `UserUnlock`, `RunnerProfile` shapes in [[data-model]].

## Accessories (`/accessories`)

The [AccessoryController](../../app/Http/Controllers/AccessoryController.php) `index` walks the `temari_unlocks` config catalog and, for each entry, resolves its slot (via the `EquippedAccessories` service), whether the user has unlocked it (`UserUnlock` rows), whether it's currently equipped, and — since Slice 7 — its live `current`/`target`/`unit` via `GoalResolver::forUser()` (the same server-side computation the retired `/goals` page used). It also returns the resolved `equipped` map (one key per slot).

[Accessories](../../resources/js/pages/Collection/Accessories.tsx) renders:

- A **live preview hero** — the currently-equipped set mapped onto [TemariProto](../../resources/js/components/temari/TemariProto.tsx), the mascot rig that actually draws each accessory (headband / medal / shirt / shorts / shoes / aura). The "Currently equipped" list mirrors what each slot holds.
- **Per-slot sections** — unlocked items first, locked items dashed-out with a lock badge, their unlock criteria, and (Slice 7) a live progress bar/count reusing the criteria text's own `current`/`target`/`unit`. On mobile the locked items collapse behind a "+N locked" toggle; on `sm+` they're always shown. Each unlocked, un-equipped item shows an **Equip** button.

### Equipping

**Equip** posts to `/api/accessories/equip` with the `unlock_key` (`preserveScroll`). The controller's `equip` method validates the key is unlocked and slotted, then **unequips every sibling in the same slot** before marking this one equipped — so a slot holds at most one item. It redirects back, and Inertia re-renders with the new `equipped` map, so the preview Temari updates immediately.

### Unlock celebration

When a run earns a *major* accessory, [AccessoryUnlockModal](../../resources/js/components/celebrations/AccessoryUnlockModal.tsx) (mounted globally) pops with Temari wearing the new item and a CTA that routes to `/accessories`. It only opens when the unlock flash carries `is_major`. The unlock itself is granted upstream during ingest — see [[gamification]].

## Notes

- The slot system, accessory rig, and poses live with the mascot — see [[temari-mascot]].
- Unlock state is stored in `user_unlocks`; resolved accessory progress and season goals are never stored, only computed live — see [[data-model]].
