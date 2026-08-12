<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Temari Goal Catalogue
|--------------------------------------------------------------------------
|
| The single canonical source of unlock grant criteria. Declarative map:
| unlock key → title, description (also shown as the locked-state
| "criteria" text on Collection/Accessories), its slot, the
| GamificationContext metric it tracks (plus a metric_key for the
| per-badge/per-rarity counters), a target, and a unit. GoalResolver reads
| this to compute `current` for every progress bar, and pulls `rarity` for
| the same key from config/temari_unlocks.php. GrantEligibleUnlocksAction
| reads the same metric/metric_key/target generically to decide grants
| (current >= target) — a new unlock needs an entry here, not a PHP change.
|
| 25 items across 6 slots (4 per slot, aura has 5), same keys and order as
| config/temari_unlocks.php.
|
*/

return [
    // ── Medals (4) ──────────────────────────────────────────────────────
    'accessory.medal_first' => [
        'title' => 'Log your 1st PR',
        'description' => 'Log 1 PR in any category.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 1,
        'unit' => 'PR',
    ],
    'accessory.medal_silver' => [
        'title' => 'Log your 5th PR',
        'description' => 'Log 5 PRs total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 5,
        'unit' => 'PR',
    ],
    'accessory.medal_gold' => [
        'title' => 'Log your 10th PR',
        'description' => 'Log 10 PRs total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 10,
        'unit' => 'PR',
    ],
    'accessory.medal_platinum' => [
        'title' => 'Log your 20th PR',
        'description' => 'Log 20 PRs total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 20,
        'unit' => 'PR',
    ],

    // ── Headband (4) ────────────────────────────────────────────────
    'accessory.headband_uncommon' => [
        'title' => 'Collect 3 Uncommon cards',
        'description' => 'Earn 3 Uncommon cards.',
        'slot' => 'headband',
        'metric' => 'rarity_count',
        'metric_key' => 'uncommon',
        'target' => 3,
        'unit' => 'cards',
    ],
    'accessory.headband_rare' => [
        'title' => 'Collect 3 Rare cards',
        'description' => 'Earn 3 Rare cards.',
        'slot' => 'headband',
        'metric' => 'rarity_count',
        'metric_key' => 'rare',
        'target' => 3,
        'unit' => 'cards',
    ],
    'accessory.headband_epic' => [
        'title' => 'Collect 3 Epic cards',
        'description' => 'Earn 3 Epic cards.',
        'slot' => 'headband',
        'metric' => 'rarity_count',
        'metric_key' => 'epic',
        'target' => 3,
        'unit' => 'cards',
    ],
    'accessory.headband_legendary' => [
        'title' => 'Collect 1 Legendary card',
        'description' => 'Earn 1 Legendary card.',
        'slot' => 'headband',
        'metric' => 'rarity_count',
        'metric_key' => 'legendary',
        'target' => 1,
        'unit' => 'cards',
    ],

    // ── Shirt (4) ───────────────────────────────────────────────────────
    'accessory.shirt_beginner' => [
        'title' => 'Log your first run',
        'description' => 'Log 1 run.',
        'slot' => 'shirt',
        'metric' => 'activity_count',
        'target' => 1,
        'unit' => 'runs',
    ],
    'accessory.shirt_early_bird' => [
        'title' => '5 morning runs',
        'description' => 'Complete 5 morning runs (before 6am).',
        'slot' => 'shirt',
        'metric' => 'badge_count',
        'metric_key' => 'early_bird',
        'target' => 5,
        'unit' => 'runs',
    ],
    'accessory.shirt_rain_warrior' => [
        'title' => '3 rainy runs',
        'description' => 'Complete 3 runs in the rain.',
        'slot' => 'shirt',
        'metric' => 'badge_count',
        'metric_key' => 'rain_warrior',
        'target' => 3,
        'unit' => 'runs',
    ],
    'accessory.shirt_legendary' => [
        'title' => 'Log 50 runs',
        'description' => 'Log 50 runs.',
        'slot' => 'shirt',
        'metric' => 'activity_count',
        'target' => 50,
        'unit' => 'runs',
    ],

    // ── Shorts (4) ─────────────────────────────────────────────────────
    'accessory.shorts_lightweight' => [
        'title' => 'Your first 5K',
        'description' => 'Log 1 run of 5 km or more.',
        'slot' => 'shorts',
        'metric' => 'five_k_plus',
        'target' => 1,
        'unit' => 'runs',
    ],
    'accessory.shorts_explorer' => [
        'title' => 'Your first 10K',
        'description' => 'Log 1 run of 10 km or more.',
        'slot' => 'shorts',
        'metric' => 'ten_k_plus',
        'target' => 1,
        'unit' => 'runs',
    ],
    'accessory.shorts_negative_split' => [
        'title' => '3 negative splits',
        'description' => 'Log 3 negative-split runs.',
        'slot' => 'shorts',
        'metric' => 'badge_count',
        'metric_key' => 'negative_split',
        'target' => 3,
        'unit' => 'runs',
    ],
    'accessory.shorts_marathon' => [
        'title' => 'Run 21K',
        'description' => 'Log 1 run of 21 km or more.',
        'slot' => 'shorts',
        'metric' => 'half_marathon',
        'target' => 1,
        'unit' => 'runs',
    ],

    // ── Shoes (4) ─────────────────────────────────────────────────────
    'accessory.shoes_basic' => [
        'title' => 'Log 10 runs',
        'description' => 'Log 10 runs.',
        'slot' => 'shoes',
        'metric' => 'activity_count',
        'target' => 10,
        'unit' => 'runs',
    ],
    'accessory.shoes_speed' => [
        'title' => 'Pace under 5:30/km',
        'description' => 'Log 1 run with an average pace under 5:30/km.',
        'slot' => 'shoes',
        'metric' => 'fast_pace',
        'target' => 1,
        'unit' => 'runs',
    ],
    'accessory.shoes_rugged' => [
        'title' => '5 runs at 10K+',
        'description' => 'Log 5 runs of 10 km or more.',
        'slot' => 'shoes',
        'metric' => 'ten_k_plus',
        'target' => 5,
        'unit' => 'runs',
    ],
    'accessory.shoes_legendary' => [
        'title' => '1,000 km total distance',
        'description' => 'Rack up 1,000 km total distance.',
        'slot' => 'shoes',
        'metric' => 'total_distance_km',
        'target' => 1000,
        'unit' => 'km',
    ],

    // ── Aura (5) ───────────────────────────────────────────────────────
    'accessory.aura_warmup' => [
        'title' => '2-week running streak',
        'description' => 'Run in 2 consecutive weeks.',
        'slot' => 'aura',
        'metric' => 'two_week_streak',
        'target' => 2,
        'unit' => 'weeks',
    ],
    'accessory.aura_heatwave' => [
        'title' => '3 hot-weather runs',
        'description' => 'Complete 3 runs with temps above 31°C.',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'heat_tamer',
        'target' => 3,
        'unit' => 'runs',
    ],
    'accessory.aura_calm' => [
        'title' => '5 runs in HR Zone 2',
        'description' => 'Log 5 runs in HR Zone 2 (under 70% max HR).',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'z2_master',
        'target' => 5,
        'unit' => 'runs',
    ],
    'accessory.aura_champion' => [
        'title' => '3 Legendary cards',
        'description' => 'Earn 3 Legendary cards.',
        'slot' => 'aura',
        'metric' => 'rarity_count',
        'metric_key' => 'legendary',
        'target' => 3,
        'unit' => 'cards',
    ],
    'accessory.aura_windrunner' => [
        'title' => '3 headwind runs',
        'description' => 'Complete 3 runs with wind above 20 km/h.',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'headwind',
        'target' => 3,
        'unit' => 'runs',
    ],
];
