<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Temari Goal Catalogue
|--------------------------------------------------------------------------
|
| Declarative map: unlock key → progress-bar metadata. Each goal carries a
| title, a description, its slot, the GamificationContext metric it tracks
| (plus a metric_key for the per-badge/per-rarity counters), a target, and
| a unit. GoalResolver reads this to compute `current` for every goal, and
| pulls `rarity` for the same key from config/temari_unlocks.php.
|
| 25 items across 6 slots (4 per slot, aura has 5), same keys and order as
| config/temari_unlocks.php.
|
*/

return [
    // ── Medals (4) ──────────────────────────────────────────────────────
    'accessory.medal_pertama' => [
        'title' => 'Log your 1st PR',
        'description' => 'Log 1 PR in any category.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 1,
        'unit' => 'PR',
    ],
    'accessory.medal_emas' => [
        'title' => 'Log your 5th PR',
        'description' => 'Log 5 PRs total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 5,
        'unit' => 'PR',
    ],
    'accessory.medal_perak' => [
        'title' => 'Log your 10th PR',
        'description' => 'Log 10 PRs total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 10,
        'unit' => 'PR',
    ],
    'accessory.medal_platina' => [
        'title' => 'Log your 20th PR',
        'description' => 'Log 20 PRs total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 20,
        'unit' => 'PR',
    ],

    // ── Headband (4) ────────────────────────────────────────────────
    'accessory.ikat_kepala_berkesan' => [
        'title' => 'Collect 3 Uncommon cards',
        'description' => 'Earn 3 Uncommon cards.',
        'slot' => 'ikat_kepala',
        'metric' => 'rarity_count',
        'metric_key' => 'uncommon',
        'target' => 3,
        'unit' => 'cards',
    ],
    'accessory.ikat_kepala_langka' => [
        'title' => 'Collect 3 Rare cards',
        'description' => 'Earn 3 Rare cards.',
        'slot' => 'ikat_kepala',
        'metric' => 'rarity_count',
        'metric_key' => 'rare',
        'target' => 3,
        'unit' => 'cards',
    ],
    'accessory.ikat_kepala_epik' => [
        'title' => 'Collect 3 Epic cards',
        'description' => 'Earn 3 Epic cards.',
        'slot' => 'ikat_kepala',
        'metric' => 'rarity_count',
        'metric_key' => 'epic',
        'target' => 3,
        'unit' => 'cards',
    ],
    'accessory.ikat_kepala_legendaris' => [
        'title' => 'Collect 1 Legendary card',
        'description' => 'Earn 1 Legendary card.',
        'slot' => 'ikat_kepala',
        'metric' => 'rarity_count',
        'metric_key' => 'legendary',
        'target' => 1,
        'unit' => 'cards',
    ],

    // ── Shirt (4) ───────────────────────────────────────────────────────
    'accessory.kaus_pemula' => [
        'title' => 'Log your first run',
        'description' => 'Log 1 run.',
        'slot' => 'kaus',
        'metric' => 'activity_count',
        'target' => 1,
        'unit' => 'runs',
    ],
    'accessory.kaus_pagi' => [
        'title' => '5 morning runs',
        'description' => 'Complete 5 morning runs (before 6am).',
        'slot' => 'kaus',
        'metric' => 'badge_count',
        'metric_key' => 'anak_pagi',
        'target' => 5,
        'unit' => 'runs',
    ],
    'accessory.kaus_hujan' => [
        'title' => '3 rainy runs',
        'description' => 'Complete 3 runs in the rain.',
        'slot' => 'kaus',
        'metric' => 'badge_count',
        'metric_key' => 'pejuang_hujan',
        'target' => 3,
        'unit' => 'runs',
    ],
    'accessory.kaus_legendaris' => [
        'title' => 'Log 50 runs',
        'description' => 'Log 50 runs.',
        'slot' => 'kaus',
        'metric' => 'activity_count',
        'target' => 50,
        'unit' => 'runs',
    ],

    // ── Shorts (4) ─────────────────────────────────────────────────────
    'accessory.celana_ringan' => [
        'title' => 'Your first 5K',
        'description' => 'Log 1 run of 5 km or more.',
        'slot' => 'celana',
        'metric' => 'five_k_plus',
        'target' => 1,
        'unit' => 'runs',
    ],
    'accessory.celana_jarak' => [
        'title' => 'Your first 10K',
        'description' => 'Log 1 run of 10 km or more.',
        'slot' => 'celana',
        'metric' => 'ten_k_plus',
        'target' => 1,
        'unit' => 'runs',
    ],
    'accessory.celana_split' => [
        'title' => '3 negative splits',
        'description' => 'Log 3 negative-split runs.',
        'slot' => 'celana',
        'metric' => 'badge_count',
        'metric_key' => 'negative_split',
        'target' => 3,
        'unit' => 'runs',
    ],
    'accessory.celana_maraton' => [
        'title' => 'Run 21K',
        'description' => 'Log 1 run of 21 km or more.',
        'slot' => 'celana',
        'metric' => 'half_marathon',
        'target' => 1,
        'unit' => 'runs',
    ],

    // ── Shoes (4) ─────────────────────────────────────────────────────
    'accessory.sepatu_basic' => [
        'title' => 'Log 10 runs',
        'description' => 'Log 10 runs.',
        'slot' => 'sepatu',
        'metric' => 'activity_count',
        'target' => 10,
        'unit' => 'runs',
    ],
    'accessory.sepatu_cepat' => [
        'title' => 'Pace under 5:30/km',
        'description' => 'Log 1 run with an average pace under 5:30/km.',
        'slot' => 'sepatu',
        'metric' => 'fast_pace',
        'target' => 1,
        'unit' => 'runs',
    ],
    'accessory.sepatu_tahan' => [
        'title' => '5 runs at 10K+',
        'description' => 'Log 5 runs of 10 km or more.',
        'slot' => 'sepatu',
        'metric' => 'ten_k_plus',
        'target' => 5,
        'unit' => 'runs',
    ],
    'accessory.sepatu_legendaris' => [
        'title' => '1,000 km total distance',
        'description' => 'Rack up 1,000 km total distance.',
        'slot' => 'sepatu',
        'metric' => 'total_distance_km',
        'target' => 1000,
        'unit' => 'km',
    ],

    // ── Aura (5) ───────────────────────────────────────────────────────
    'accessory.aura_pemanasan' => [
        'title' => '2-week running streak',
        'description' => 'Run in 2 consecutive weeks.',
        'slot' => 'aura',
        'metric' => 'two_week_streak',
        'target' => 2,
        'unit' => 'weeks',
    ],
    'accessory.aura_gerah' => [
        'title' => '3 hot-weather runs',
        'description' => 'Complete 3 runs with temps above 31°C.',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'hari_panas',
        'target' => 3,
        'unit' => 'runs',
    ],
    'accessory.aura_tenang' => [
        'title' => '5 runs in HR Zone 2',
        'description' => 'Log 5 runs in HR Zone 2 (under 70% max HR).',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'z2_master',
        'target' => 5,
        'unit' => 'runs',
    ],
    'accessory.aura_jagoan' => [
        'title' => '3 Legendary cards',
        'description' => 'Earn 3 Legendary cards.',
        'slot' => 'aura',
        'metric' => 'rarity_count',
        'metric_key' => 'legendary',
        'target' => 3,
        'unit' => 'cards',
    ],
    'accessory.aura_angin' => [
        'title' => '3 headwind runs',
        'description' => 'Complete 3 runs with wind above 20 km/h.',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'lawan_angin',
        'target' => 3,
        'unit' => 'runs',
    ],
];
