---
title: Accessories
description: The accessory unlock catalog — still granted at ingest and surfaced in the inbox; the wardrobe that wore them is gone.
tags: [feature, collection]
status: living
reviewed: 2026-08-31
code_refs:
  - app/Actions/Gamification/GrantEligibleUnlocksAction.php
---

# Accessories

**Accessories** were the wardrobe of what a runner had earned and could put on Temari, organized
into six equipment **slots**: medal, headband, shirt, shorts, shoes, aura.

**There is no accessory surface any more.** The prototype draws no wardrobe, and `PP2` cut the
mascot rig that was the only thing capable of wearing an item, so the page went with it: the
`/accessories` route, its `/api/accessories/equip` write, `AccessoryController`,
`EquipAccessoryRequest`, `Collection/Accessories.tsx`, `lib/equippedAccessories.ts`, and the three
legacy redirects that pointed at it are all gone. The badge board and
`/cards` had already retired the same way — badges surface as chips on `/trends`' fitness panel,
cards inline on [[run-history]].

## What still runs

The **grant** engine is untouched; the **wardrobe** is entirely gone.

- [GrantEligibleUnlocksAction](../../app/Actions/Gamification/GrantEligibleUnlocksAction.php)
  still grants accessories at ingest, writing `user_unlocks` rows. See [[gamification]].
- `config/temari_unlocks.php` (display) and `config/temari_goals.php` (grant criteria) are intact,
  and `GoalResolver` still feeds `LifetimeStatsTool`'s "accessories unlocked out of the total".

`W2` swept everything on the wearing side, because none of it could be read or changed: the
`EquippedAccessories` service, its `equippedAccessories` shared prop (recomputed and cached on
*every* page load for a value no component destructured), its `SharedPropCacheKey` entry, and the
`user_unlocks.equipped` column itself — which had no production write path left at all once
`AccessoryController::equip()` went, only a demo seeder and a factory state. The 25 item SVGs and
their generator went with the rest of the unread brand preview layer. Git history holds all of it.

An unlock still lands as an inbox row with its rarity badge, which is the one place the prototype
does draw unlocks. The badge reads its tier out of `config/temari_unlocks.php` by `unlock_key` at
render time — `UnlockGrantedNotification`'s stored payload has never carried one, so until `PS9`
resolved it read-side the badge had a branch and no data — see [[notification-inbox]].

## Notes

- Unlock state is stored in `user_unlocks`; accessory progress and season goals are never stored,
  only computed live — see [[data-model]].
- The unlock celebration (takeover modal + toast) was cut in `PP3` (P14).
