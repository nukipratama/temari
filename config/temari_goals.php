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
    // ── Medali (4) ──────────────────────────────────────────────────────
    'accessory.medal_pertama' => [
        'title' => 'Catat PR ke-1',
        'description' => 'Catat 1 PR di kategori apapun.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 1,
        'unit' => 'PR',
    ],
    'accessory.medal_emas' => [
        'title' => 'Catat PR ke-5',
        'description' => 'Catat 5 PR total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 5,
        'unit' => 'PR',
    ],
    'accessory.medal_perak' => [
        'title' => 'Catat PR ke-10',
        'description' => 'Catat 10 PR total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 10,
        'unit' => 'PR',
    ],
    'accessory.medal_platina' => [
        'title' => 'Catat PR ke-20',
        'description' => 'Catat 20 PR total.',
        'slot' => 'medal',
        'metric' => 'pr_count',
        'target' => 20,
        'unit' => 'PR',
    ],

    // ── Ikat Kepala (4) ────────────────────────────────────────────────
    'accessory.ikat_kepala_berkesan' => [
        'title' => 'Kumpulkan 3 kartu Berkesan',
        'description' => 'Dapatkan 3 kartu Berkesan.',
        'slot' => 'ikat_kepala',
        'metric' => 'rarity_count',
        'metric_key' => 'uncommon',
        'target' => 3,
        'unit' => 'kartu',
    ],
    'accessory.ikat_kepala_langka' => [
        'title' => 'Kumpulkan 3 kartu Langka',
        'description' => 'Dapatkan 3 kartu Langka.',
        'slot' => 'ikat_kepala',
        'metric' => 'rarity_count',
        'metric_key' => 'rare',
        'target' => 3,
        'unit' => 'kartu',
    ],
    'accessory.ikat_kepala_epik' => [
        'title' => 'Kumpulkan 3 kartu Istimewa',
        'description' => 'Dapatkan 3 kartu Istimewa.',
        'slot' => 'ikat_kepala',
        'metric' => 'rarity_count',
        'metric_key' => 'epic',
        'target' => 3,
        'unit' => 'kartu',
    ],
    'accessory.ikat_kepala_legendaris' => [
        'title' => 'Kumpulkan 1 kartu Legendaris',
        'description' => 'Dapatkan 1 kartu Legendaris.',
        'slot' => 'ikat_kepala',
        'metric' => 'rarity_count',
        'metric_key' => 'legendary',
        'target' => 1,
        'unit' => 'kartu',
    ],

    // ── Kaus (4) ───────────────────────────────────────────────────────
    'accessory.kaus_pemula' => [
        'title' => 'Catat lari pertama',
        'description' => 'Catat 1 aktivitas lari.',
        'slot' => 'kaus',
        'metric' => 'activity_count',
        'target' => 1,
        'unit' => 'lari',
    ],
    'accessory.kaus_pagi' => [
        'title' => '5 lari pagi',
        'description' => 'Selesaikan 5 lari pagi (sebelum jam 6).',
        'slot' => 'kaus',
        'metric' => 'badge_count',
        'metric_key' => 'anak_pagi',
        'target' => 5,
        'unit' => 'lari',
    ],
    'accessory.kaus_hujan' => [
        'title' => '3 lari pas hujan',
        'description' => 'Selesaikan 3 lari pas hujan.',
        'slot' => 'kaus',
        'metric' => 'badge_count',
        'metric_key' => 'pejuang_hujan',
        'target' => 3,
        'unit' => 'lari',
    ],
    'accessory.kaus_legendaris' => [
        'title' => 'Catat 50 lari',
        'description' => 'Catat 50 aktivitas lari.',
        'slot' => 'kaus',
        'metric' => 'activity_count',
        'target' => 50,
        'unit' => 'lari',
    ],

    // ── Celana (4) ─────────────────────────────────────────────────────
    'accessory.celana_ringan' => [
        'title' => 'Lari 5 km pertama',
        'description' => 'Catat 1 lari sejauh 5 km atau lebih.',
        'slot' => 'celana',
        'metric' => 'five_k_plus',
        'target' => 1,
        'unit' => 'lari',
    ],
    'accessory.celana_jarak' => [
        'title' => 'Lari 10 km pertama',
        'description' => 'Catat 1 lari sejauh 10 km atau lebih.',
        'slot' => 'celana',
        'metric' => 'ten_k_plus',
        'target' => 1,
        'unit' => 'lari',
    ],
    'accessory.celana_split' => [
        'title' => '3 negative split',
        'description' => 'Catat 3 lari negative split.',
        'slot' => 'celana',
        'metric' => 'badge_count',
        'metric_key' => 'negative_split',
        'target' => 3,
        'unit' => 'lari',
    ],
    'accessory.celana_maraton' => [
        'title' => 'Lari 21 km',
        'description' => 'Catat 1 lari sejauh 21 km atau lebih.',
        'slot' => 'celana',
        'metric' => 'half_marathon',
        'target' => 1,
        'unit' => 'lari',
    ],

    // ── Sepatu (4) ─────────────────────────────────────────────────────
    'accessory.sepatu_basic' => [
        'title' => 'Catat 10 lari',
        'description' => 'Catat 10 aktivitas lari.',
        'slot' => 'sepatu',
        'metric' => 'activity_count',
        'target' => 10,
        'unit' => 'lari',
    ],
    'accessory.sepatu_cepat' => [
        'title' => 'Pace di bawah 5:30/km',
        'description' => 'Catat 1 lari dengan rata-rata pace di bawah 5:30/km.',
        'slot' => 'sepatu',
        'metric' => 'fast_pace',
        'target' => 1,
        'unit' => 'lari',
    ],
    'accessory.sepatu_tahan' => [
        'title' => '5 lari 10 km+',
        'description' => 'Catat 5 lari sejauh 10 km atau lebih.',
        'slot' => 'sepatu',
        'metric' => 'ten_k_plus',
        'target' => 5,
        'unit' => 'lari',
    ],
    'accessory.sepatu_legendaris' => [
        'title' => 'Total jarak 1000 km',
        'description' => 'Kumpulin jarak sampai 1000 km.',
        'slot' => 'sepatu',
        'metric' => 'total_distance_km',
        'target' => 1000,
        'unit' => 'km',
    ],

    // ── Aura (5) ───────────────────────────────────────────────────────
    'accessory.aura_pemanasan' => [
        'title' => '2 minggu beruntun lari',
        'description' => 'Lari di 2 minggu beruntun.',
        'slot' => 'aura',
        'metric' => 'two_week_streak',
        'target' => 2,
        'unit' => 'minggu',
    ],
    'accessory.aura_gerah' => [
        'title' => '3 lari pas gerah',
        'description' => 'Selesaikan 3 lari saat suhu di atas 31°C.',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'hari_panas',
        'target' => 3,
        'unit' => 'lari',
    ],
    'accessory.aura_tenang' => [
        'title' => '5 lari Zona HR 2',
        'description' => 'Catat 5 lari di Zona HR 2 (bawah 70% HR maks).',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'z2_master',
        'target' => 5,
        'unit' => 'lari',
    ],
    'accessory.aura_jagoan' => [
        'title' => '3 kartu Legendaris',
        'description' => 'Dapatkan 3 kartu Legendaris.',
        'slot' => 'aura',
        'metric' => 'rarity_count',
        'metric_key' => 'legendary',
        'target' => 3,
        'unit' => 'kartu',
    ],
    'accessory.aura_angin' => [
        'title' => '3 lari lawan angin',
        'description' => 'Selesaikan 3 lari saat angin di atas 20 km/j.',
        'slot' => 'aura',
        'metric' => 'badge_count',
        'metric_key' => 'lawan_angin',
        'target' => 3,
        'unit' => 'lari',
    ],
];
