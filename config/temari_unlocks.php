<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Temari Accessory Unlocks
|--------------------------------------------------------------------------
|
| Declarative map: unlock_key → metadata. Each accessory has a name, an
| icon (Iconify), a short description, a rarity tier, and a criteria
| summary shown in locked silhouette state on the Collection/Accessories grid.
|
| 25 items across 6 slots (4 per slot, aura has 5). Slots: medal,
| headband, shirt, shorts, shoes, aura.
|
*/

return [
    // ── Medals (4) ──────────────────────────────────────────────────────
    'accessory.medal_first' => [
        'name' => 'First Medal',
        'slot' => 'medal',
        'rarity' => 'common',
        'icon' => 'mdi:medal',
        'description' => 'A brass medal for your first PR.',
        'criteria' => 'Log 1 PR in any category.',
    ],
    'accessory.medal_gold' => [
        'name' => 'Gold Medal',
        'slot' => 'medal',
        'rarity' => 'uncommon',
        'icon' => 'mdi:medal-outline',
        'description' => 'A thin gold medal for 5 total PRs.',
        'criteria' => 'Log 5 PRs total.',
    ],
    'accessory.medal_silver' => [
        'name' => 'Silver Medal',
        'slot' => 'medal',
        'rarity' => 'rare',
        'icon' => 'mdi:medal',
        'description' => "A silver medal once you've logged 10 PRs.",
        'criteria' => 'Log 10 PRs total.',
    ],
    'accessory.medal_platinum' => [
        'name' => 'Platinum Medal',
        'slot' => 'medal',
        'rarity' => 'epic',
        'icon' => 'mdi:trophy',
        'description' => 'A platinum medal for PR collectors, 20 and counting.',
        'criteria' => 'Log 20 PRs total.',
    ],

    // ── Headband (4) ────────────────────────────────────────────────
    'accessory.headband_uncommon' => [
        'name' => 'Uncommon Headband',
        'slot' => 'headband',
        'rarity' => 'uncommon',
        'icon' => 'mdi:bandage',
        'description' => "A green headband once you've earned 3 Uncommon cards.",
        'criteria' => 'Earn 3 Uncommon cards.',
    ],
    'accessory.headband_rare' => [
        'name' => 'Rare Headband',
        'slot' => 'headband',
        'rarity' => 'rare',
        'icon' => 'mdi:bandage',
        'description' => "A blue headband once you've earned 3 Rare cards.",
        'criteria' => 'Earn 3 Rare cards.',
    ],
    'accessory.headband_epic' => [
        'name' => 'Epic Headband',
        'slot' => 'headband',
        'rarity' => 'epic',
        'icon' => 'mdi:bandage',
        'description' => 'A purple headband for a collection of 3 Epic cards.',
        'criteria' => 'Earn 3 Epic cards.',
    ],
    'accessory.headband_legendary' => [
        'name' => 'Legendary Headband',
        'slot' => 'headband',
        'rarity' => 'legendary',
        'icon' => 'mdi:bandage',
        'description' => 'A gold headband, only for those holding a Legendary card.',
        'criteria' => 'Earn 1 Legendary card.',
    ],

    // ── Shirt (4) ───────────────────────────────────────────────────────
    'accessory.shirt_beginner' => [
        'name' => 'Beginner Shirt',
        'slot' => 'shirt',
        'rarity' => 'common',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'A plain white tee for your first run.',
        'criteria' => 'Log 1 run.',
    ],
    'accessory.shirt_early_bird' => [
        'name' => 'Early Bird Shirt',
        'slot' => 'shirt',
        'rarity' => 'uncommon',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'A warm tee for collecting 5 morning runs.',
        'criteria' => 'Complete 5 morning runs (before 6am).',
    ],
    'accessory.shirt_rain_warrior' => [
        'name' => 'Rain Warrior Shirt',
        'slot' => 'shirt',
        'rarity' => 'rare',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'A water-resistant tee for braving 3 rainy runs.',
        'criteria' => 'Complete 3 runs in the rain.',
    ],
    'accessory.shirt_legendary' => [
        'name' => 'Legendary Shirt',
        'slot' => 'shirt',
        'rarity' => 'legendary',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'A gold tee, only for those with 50 runs logged.',
        'criteria' => 'Log 50 runs.',
    ],

    // ── Shorts (4) ─────────────────────────────────────────────────────
    'accessory.shorts_lightweight' => [
        'name' => 'Lightweight Shorts',
        'slot' => 'shorts',
        'rarity' => 'common',
        'icon' => 'mdi:lingerie',
        'description' => 'Lightweight shorts for your first 5K.',
        'criteria' => 'Log 1 run of 5 km or more.',
    ],
    'accessory.shorts_explorer' => [
        'name' => 'Explorer Shorts',
        'slot' => 'shorts',
        'rarity' => 'uncommon',
        'icon' => 'mdi:lingerie',
        'description' => 'Everyday shorts for chasing down 10K.',
        'criteria' => 'Log 1 run of 10 km or more.',
    ],
    'accessory.shorts_negative_split' => [
        'name' => 'Negative Split Shorts',
        'slot' => 'shorts',
        'rarity' => 'rare',
        'icon' => 'mdi:lingerie',
        'description' => 'Shorts for pulling off 3 negative splits.',
        'criteria' => 'Log 3 negative-split runs.',
    ],
    'accessory.shorts_marathon' => [
        'name' => 'Marathon Shorts',
        'slot' => 'shorts',
        'rarity' => 'epic',
        'icon' => 'mdi:lingerie',
        'description' => 'Champion shorts for going the 21K distance.',
        'criteria' => 'Log 1 run of 21 km or more.',
    ],

    // ── Shoes (4) ─────────────────────────────────────────────────────
    'accessory.shoes_basic' => [
        'name' => 'Basic Shoes',
        'slot' => 'shoes',
        'rarity' => 'common',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Basic shoes for your first 10 runs.',
        'criteria' => 'Log 10 runs.',
    ],
    'accessory.shoes_speed' => [
        'name' => 'Speed Shoes',
        'slot' => 'shoes',
        'rarity' => 'uncommon',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Racing shoes for hitting a 5:30/km pace.',
        'criteria' => 'Log 1 run with an average pace under 5:30/km.',
    ],
    'accessory.shoes_rugged' => [
        'name' => 'Rugged Shoes',
        'slot' => 'shoes',
        'rarity' => 'rare',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Tough shoes for the 10K+ regular, 5 runs deep.',
        'criteria' => 'Log 5 runs of 10 km or more.',
    ],
    'accessory.shoes_legendary' => [
        'name' => 'Legendary Shoes',
        'slot' => 'shoes',
        'rarity' => 'legendary',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Gold shoes for 1,000 km logged and counting.',
        'criteria' => 'Accumulate 1,000 km.',
    ],

    // ── Aura (4) ───────────────────────────────────────────────────────
    'accessory.aura_warmup' => [
        'name' => 'Warm-Up Aura',
        'slot' => 'aura',
        'rarity' => 'common',
        'icon' => 'mdi:blur',
        'description' => 'A warm aura for staying consistent 2 weeks running.',
        'criteria' => 'Run in 2 consecutive weeks.',
    ],
    'accessory.aura_heatwave' => [
        'name' => 'Heatwave Aura',
        'slot' => 'aura',
        'rarity' => 'uncommon',
        'icon' => 'mdi:fire',
        'description' => 'A fiery aura for braving 3 sweltering runs.',
        'criteria' => 'Complete 3 runs with temps above 31°C.',
    ],
    'accessory.aura_calm' => [
        'name' => 'Calm Aura',
        'slot' => 'aura',
        'rarity' => 'rare',
        'icon' => 'mdi:blur',
        'description' => 'A cool aura for holding HR Zone 2 across 5 runs.',
        'criteria' => 'Log 5 runs in HR Zone 2 (under 70% HRmax).',
    ],
    'accessory.aura_champion' => [
        'name' => 'Champion Aura',
        'slot' => 'aura',
        'rarity' => 'epic',
        'icon' => 'mdi:blur',
        'description' => 'A lightning aura for holding 3 Legendary cards.',
        'criteria' => 'Earn 3 Legendary cards.',
    ],
    'accessory.aura_windrunner' => [
        'name' => 'Windrunner Aura',
        'slot' => 'aura',
        'rarity' => 'rare',
        'icon' => 'mdi:weather-windy',
        'description' => 'A windswept aura for pushing pace through strong wind, 3 times.',
        'criteria' => 'Complete 3 runs with wind above 20 km/h.',
    ],
];
