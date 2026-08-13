---
title: Accessories & the badge board
description: The accessories page (equip/unequip with a live Temari preview, live unlock progress) and the badge board (all Badge cases plus the rest-day reward, lifetime vs this-season).
tags: [feature, collection]
status: living
reviewed: 2026-08-10
code_refs:
  - resources/js/pages/Collection/Accessories.tsx
  - resources/js/pages/Collection/Badges.tsx
  - app/Http/Controllers/AksesoriController.php
  - app/Http/Controllers/BadgeBoardController.php
  - resources/js/components/temari/TemariProto.tsx
  - resources/js/components/celebrations/AccessoryUnlockModal.tsx
  - resources/js/components/koleksi/KoleksiTabs.tsx
---

# Accessories & the badge board

Two collection sub-tabs: **Accessories** (`/accessories`) is the wardrobe of what's been earned and can be put on Temari, now also showing live progress toward what's still locked. **Badges** (`/badges`) is the full badge board — all `Badge` cases plus the rest-day reward, lifetime counts and this-season counts side by side. Accessories are organized by six equipment **slots**: medal, headband, shirt, shorts, shoes, aura; badges aren't slotted.

**Navigation:** `route('accessories')` → `/accessories` (`AksesoriController::index`); `route('badges')` → `/badges` (`BadgeBoardController::index`). The old `/goals` accessory-progress page (Slice 5 through Slice 6) and its `/target` legacy redirect both retired in Slice 7 — both now redirect straight to `/accessories`, where the progress numbers moved.

## System dependencies

- **Gamification** — accessory progress and grants come from `GoalResolver`/`GrantEligibleUnlocksAction`; badge counts from `RunCard`; the rest-day reward from `GrantSeasonUnlocksAction`. See [[gamification]].
- **Season** — the badge board's "this season" counts are scoped to the active `Season`'s date range; see [[gamification]] and [[plan-periodizer]].
- **Temari mascot** — the live preview hero uses `TemariProto` to render equipped gear; see [[temari-mascot]].
- **Data model** — `UserUnlock`, `RunnerProfile` shapes in [[data-model]].

## Accessories (`/accessories`)

The [AksesoriController](../../app/Http/Controllers/AksesoriController.php) `index` walks the `temari_unlocks` config catalog and, for each entry, resolves its slot (via the `EquippedAccessories` service), whether the user has unlocked it (`UserUnlock` rows), whether it's currently equipped, and — since Slice 7 — its live `current`/`target`/`unit` via `GoalResolver::forUser()` (the same server-side computation the retired `/goals` page used). It also returns the resolved `equipped` map (one key per slot).

[Accessories](../../resources/js/pages/Collection/Accessories.tsx) renders:

- A **live preview hero** — the currently-equipped set mapped onto [TemariProto](../../resources/js/components/temari/TemariProto.tsx), the mascot rig that actually draws each accessory (headband / medal / shirt / shorts / shoes / aura). The "Currently equipped" list mirrors what each slot holds.
- **Per-slot sections** — unlocked items first, locked items dashed-out with a lock badge, their unlock criteria, and (Slice 7) a live progress bar/count reusing the criteria text's own `current`/`target`/`unit`. On mobile the locked items collapse behind a "+N locked" toggle; on `sm+` they're always shown. Each unlocked, un-equipped item shows an **Equip** button.

### Equipping

**Equip** posts to `/api/accessories/equip` with the `unlock_key` (`preserveScroll`). The controller's `equip` method validates the key is unlocked and slotted, then **unequips every sibling in the same slot** before marking this one equipped — so a slot holds at most one item. It redirects back, and Inertia re-renders with the new `equipped` map, so the preview Temari updates immediately.

### Unlock celebration

When a run earns a *major* accessory, [AccessoryUnlockModal](../../resources/js/components/celebrations/AccessoryUnlockModal.tsx) (mounted globally) pops with Temari wearing the new item and a CTA that routes to `/accessories`. It only opens when the unlock flash carries `is_major`. The unlock itself is granted upstream during ingest — see [[gamification]].

## Badge board (`/badges`)

[BadgeBoardController](../../app/Http/Controllers/BadgeBoardController.php) ships one flat list: all 16 `Badge` cases plus the rest-day reward as a visually-equivalent 17th entry (`key: 'season.rest_honored'`), even though the two are backed by different mechanisms (`Badge` enum vs. `UserUnlock`-shaped) — see [[gamification]] for why. Each item carries `unlocked`, `lifetime_count`, and `season_count`.

[Badges](../../resources/js/pages/Collection/Badges.tsx) reuses `runcard.ts`'s existing `BADGE_LABELS`/`BADGE_ABILITY` maps for the 16 real badges' name/emblem/criterion text (no server-side duplicate catalog needed) and hardcodes its own display text for the one rest-day entry, since that has no `Badge` case to read from. Locked items show their criterion; earned items show both counts.

`KoleksiTabs`'s 4th sub-tab is `badges` (was `target` → `/goals`, retired in Slice 7).

## Notes

- The slot system, accessory rig, and poses live with the mascot — see [[temari-mascot]].
- Unlock state is stored in `user_unlocks`; resolved accessory progress and season goals are never stored, only computed live — see [[data-model]].
