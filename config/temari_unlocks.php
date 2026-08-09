<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Temari Accessory Unlocks
|--------------------------------------------------------------------------
|
| Declarative map: unlock_key → metadata. Each accessory has a name, an
| icon (Iconify), a short description, a rarity tier, and a criteria
| summary shown in locked silhouette state on the Profil koleksi grid.
|
| 25 items across 6 slots (4 per slot, aura has 5). Slots: medal,
| ikat_kepala, kaus, celana, sepatu, aura.
|
*/

return [
    // ── Medals (4) ──────────────────────────────────────────────────────
    'accessory.medal_pertama' => [
        'name' => 'First Medal',
        'slot' => 'medal',
        'rarity' => 'common',
        'icon' => 'mdi:medal',
        'description' => 'A brass medal for your first PR.',
        'criteria' => 'Log 1 PR in any category.',
    ],
    'accessory.medal_emas' => [
        'name' => 'Gold Medal',
        'slot' => 'medal',
        'rarity' => 'uncommon',
        'icon' => 'mdi:medal-outline',
        'description' => 'A thin gold medal for 5 total PRs.',
        'criteria' => 'Log 5 PRs total.',
    ],
    'accessory.medal_perak' => [
        'name' => 'Silver Medal',
        'slot' => 'medal',
        'rarity' => 'rare',
        'icon' => 'mdi:medal',
        'description' => "A silver medal once you've logged 10 PRs.",
        'criteria' => 'Log 10 PRs total.',
    ],
    'accessory.medal_platina' => [
        'name' => 'Platinum Medal',
        'slot' => 'medal',
        'rarity' => 'epic',
        'icon' => 'mdi:trophy',
        'description' => 'A platinum medal for PR collectors, 20 and counting.',
        'criteria' => 'Log 20 PRs total.',
    ],

    // ── Headband (4) ────────────────────────────────────────────────
    'accessory.ikat_kepala_berkesan' => [
        'name' => 'Uncommon Headband',
        'slot' => 'ikat_kepala',
        'rarity' => 'uncommon',
        'icon' => 'mdi:bandage',
        'description' => "A green headband once you've earned 3 Uncommon cards.",
        'criteria' => 'Earn 3 Uncommon cards.',
    ],
    'accessory.ikat_kepala_langka' => [
        'name' => 'Rare Headband',
        'slot' => 'ikat_kepala',
        'rarity' => 'rare',
        'icon' => 'mdi:bandage',
        'description' => "A blue headband once you've earned 3 Rare cards.",
        'criteria' => 'Earn 3 Rare cards.',
    ],
    'accessory.ikat_kepala_epik' => [
        'name' => 'Epic Headband',
        'slot' => 'ikat_kepala',
        'rarity' => 'epic',
        'icon' => 'mdi:bandage',
        'description' => 'A purple headband for a collection of 3 Epic cards.',
        'criteria' => 'Earn 3 Epic cards.',
    ],
    'accessory.ikat_kepala_legendaris' => [
        'name' => 'Legendary Headband',
        'slot' => 'ikat_kepala',
        'rarity' => 'legendary',
        'icon' => 'mdi:bandage',
        'description' => 'A gold headband, only for those holding a Legendary card.',
        'criteria' => 'Earn 1 Legendary card.',
    ],

    // ── Shirt (4) ───────────────────────────────────────────────────────
    'accessory.kaus_pemula' => [
        'name' => 'Beginner Shirt',
        'slot' => 'kaus',
        'rarity' => 'common',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'A plain white tee for your first run.',
        'criteria' => 'Log 1 run.',
    ],
    'accessory.kaus_pagi' => [
        'name' => 'Early Bird Shirt',
        'slot' => 'kaus',
        'rarity' => 'uncommon',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'A warm tee for collecting 5 morning runs.',
        'criteria' => 'Complete 5 morning runs (before 6am).',
    ],
    'accessory.kaus_hujan' => [
        'name' => 'Rain Warrior Shirt',
        'slot' => 'kaus',
        'rarity' => 'rare',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'A water-resistant tee for braving 3 rainy runs.',
        'criteria' => 'Complete 3 runs in the rain.',
    ],
    'accessory.kaus_legendaris' => [
        'name' => 'Legendary Shirt',
        'slot' => 'kaus',
        'rarity' => 'legendary',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'A gold tee, only for those with 50 runs logged.',
        'criteria' => 'Log 50 runs.',
    ],

    // ── Shorts (4) ─────────────────────────────────────────────────────
    'accessory.celana_ringan' => [
        'name' => 'Lightweight Shorts',
        'slot' => 'celana',
        'rarity' => 'common',
        'icon' => 'mdi:lingerie',
        'description' => 'Lightweight shorts for your first 5K.',
        'criteria' => 'Log 1 run of 5 km or more.',
    ],
    'accessory.celana_jarak' => [
        'name' => 'Explorer Shorts',
        'slot' => 'celana',
        'rarity' => 'uncommon',
        'icon' => 'mdi:lingerie',
        'description' => 'Everyday shorts for chasing down 10K.',
        'criteria' => 'Log 1 run of 10 km or more.',
    ],
    'accessory.celana_split' => [
        'name' => 'Negative Split Shorts',
        'slot' => 'celana',
        'rarity' => 'rare',
        'icon' => 'mdi:lingerie',
        'description' => 'Shorts for pulling off 3 negative splits.',
        'criteria' => 'Log 3 negative-split runs.',
    ],
    'accessory.celana_maraton' => [
        'name' => 'Marathon Shorts',
        'slot' => 'celana',
        'rarity' => 'epic',
        'icon' => 'mdi:lingerie',
        'description' => 'Champion shorts for going the 21K distance.',
        'criteria' => 'Log 1 run of 21 km or more.',
    ],

    // ── Shoes (4) ─────────────────────────────────────────────────────
    'accessory.sepatu_basic' => [
        'name' => 'Basic Shoes',
        'slot' => 'sepatu',
        'rarity' => 'common',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Basic shoes for your first 10 runs.',
        'criteria' => 'Log 10 runs.',
    ],
    'accessory.sepatu_cepat' => [
        'name' => 'Speed Shoes',
        'slot' => 'sepatu',
        'rarity' => 'uncommon',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Racing shoes for hitting a 5:30/km pace.',
        'criteria' => 'Log 1 run with an average pace under 5:30/km.',
    ],
    'accessory.sepatu_tahan' => [
        'name' => 'Rugged Shoes',
        'slot' => 'sepatu',
        'rarity' => 'rare',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Tough shoes for the 10K+ regular, 5 runs deep.',
        'criteria' => 'Log 5 runs of 10 km or more.',
    ],
    'accessory.sepatu_legendaris' => [
        'name' => 'Legendary Shoes',
        'slot' => 'sepatu',
        'rarity' => 'legendary',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Gold shoes for 1,000 km logged and counting.',
        'criteria' => 'Accumulate 1,000 km.',
    ],

    // ── Aura (4) ───────────────────────────────────────────────────────
    'accessory.aura_pemanasan' => [
        'name' => 'Warm-Up Aura',
        'slot' => 'aura',
        'rarity' => 'common',
        'icon' => 'mdi:blur',
        'description' => 'A warm aura for staying consistent 2 weeks running.',
        'criteria' => 'Run in 2 consecutive weeks.',
    ],
    'accessory.aura_gerah' => [
        'name' => 'Heatwave Aura',
        'slot' => 'aura',
        'rarity' => 'uncommon',
        'icon' => 'mdi:fire',
        'description' => 'A fiery aura for braving 3 sweltering runs.',
        'criteria' => 'Complete 3 runs with temps above 31°C.',
    ],
    'accessory.aura_tenang' => [
        'name' => 'Calm Aura',
        'slot' => 'aura',
        'rarity' => 'rare',
        'icon' => 'mdi:blur',
        'description' => 'A cool aura for holding HR Zone 2 across 5 runs.',
        'criteria' => 'Log 5 runs in HR Zone 2 (under 70% HRmax).',
    ],
    'accessory.aura_jagoan' => [
        'name' => 'Champion Aura',
        'slot' => 'aura',
        'rarity' => 'epic',
        'icon' => 'mdi:blur',
        'description' => 'A lightning aura for holding 3 Legendary cards.',
        'criteria' => 'Earn 3 Legendary cards.',
    ],
    'accessory.aura_angin' => [
        'name' => 'Windrunner Aura',
        'slot' => 'aura',
        'rarity' => 'rare',
        'icon' => 'mdi:weather-windy',
        'description' => 'A windswept aura for pushing pace through strong wind, 3 times.',
        'criteria' => 'Complete 3 runs with wind above 20 km/h.',
    ],
];
