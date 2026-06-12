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
| 24 items across 6 slots (4 per slot). Slots: medal, ikat_kepala,
| kaus, celana, sepatu, aura.
|
*/

return [
    // ── Medali (4) ──────────────────────────────────────────────────────
    'accessory.medal_pertama' => [
        'name' => 'Medali Pertama',
        'slot' => 'medal',
        'rarity' => 'common',
        'icon' => 'mdi:medal',
        'description' => 'Medali kuningan buat PR pertama kamu.',
        'criteria' => 'Catat 1 PR di kategori apapun.',
    ],
    'accessory.medal_emas' => [
        'name' => 'Medali Emas',
        'slot' => 'medal',
        'rarity' => 'uncommon',
        'icon' => 'mdi:medal-outline',
        'description' => 'Medali emas tipis buat 5 PR total.',
        'criteria' => 'Catat 5 PR total.',
    ],
    'accessory.medal_perak' => [
        'name' => 'Medali Perak',
        'slot' => 'medal',
        'rarity' => 'rare',
        'icon' => 'mdi:medal',
        'description' => 'Medali perak buat yang udah 10 PR.',
        'criteria' => 'Catat 10 PR total.',
    ],
    'accessory.medal_platina' => [
        'name' => 'Medali Platina',
        'slot' => 'medal',
        'rarity' => 'epic',
        'icon' => 'mdi:trophy',
        'description' => 'Medali platina buat kolektor 20 PR.',
        'criteria' => 'Catat 20 PR total.',
    ],

    // ── Ikat Kepala (4) ────────────────────────────────────────────────
    'accessory.ikat_kepala_berkesan' => [
        'name' => 'Ikat Kepala Berkesan',
        'slot' => 'ikat_kepala',
        'rarity' => 'uncommon',
        'icon' => 'mdi:bandage',
        'description' => 'Ikat kepala hijau buat yang udah dapat 3 kartu Berkesan.',
        'criteria' => 'Dapatkan 3 kartu Berkesan.',
    ],
    'accessory.ikat_kepala_langka' => [
        'name' => 'Ikat Kepala Langka',
        'slot' => 'ikat_kepala',
        'rarity' => 'rare',
        'icon' => 'mdi:bandage',
        'description' => 'Ikat kepala biru buat yang udah dapat 3 kartu Langka.',
        'criteria' => 'Dapatkan 3 kartu Langka.',
    ],
    'accessory.ikat_kepala_epik' => [
        'name' => 'Ikat Kepala Luar Biasa',
        'slot' => 'ikat_kepala',
        'rarity' => 'epic',
        'icon' => 'mdi:bandage',
        'description' => 'Ikat kepala ungu buat koleksi 3 kartu Luar Biasa.',
        'criteria' => 'Dapatkan 3 kartu Luar Biasa.',
    ],
    'accessory.ikat_kepala_legendaris' => [
        'name' => 'Ikat Kepala Legendaris',
        'slot' => 'ikat_kepala',
        'rarity' => 'legendary',
        'icon' => 'mdi:bandage',
        'description' => 'Ikat kepala emas, cuma buat yang punya kartu Legendaris.',
        'criteria' => 'Dapatkan 1 kartu Legendaris.',
    ],

    // ── Kaus (4) ───────────────────────────────────────────────────────
    'accessory.kaus_pemula' => [
        'name' => 'Kaus Pemula',
        'slot' => 'kaus',
        'rarity' => 'common',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'Kaus putih polos buat lari pertama kamu.',
        'criteria' => 'Catat 1 aktivitas lari.',
    ],
    'accessory.kaus_pagi' => [
        'name' => 'Kaus Anak Pagi',
        'slot' => 'kaus',
        'rarity' => 'uncommon',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'Kaus hangat buat yang kumpulin 5 lari pagi.',
        'criteria' => 'Selesaikan 5 lari pagi (sebelum jam 6).',
    ],
    'accessory.kaus_hujan' => [
        'name' => 'Kaus Pejuang Hujan',
        'slot' => 'kaus',
        'rarity' => 'rare',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'Kaus tahan air buat yang nekat lari pas hujan 3 kali.',
        'criteria' => 'Selesaikan 3 lari pas hujan.',
    ],
    'accessory.kaus_legendaris' => [
        'name' => 'Kaus Legendaris',
        'slot' => 'kaus',
        'rarity' => 'legendary',
        'icon' => 'mdi:tshirt-crew',
        'description' => 'Kaus emas, cuma buat yang udah 50 lari.',
        'criteria' => 'Catat 50 aktivitas lari.',
    ],

    // ── Celana (4) ─────────────────────────────────────────────────────
    'accessory.celana_ringan' => [
        'name' => 'Celana Ringan',
        'slot' => 'celana',
        'rarity' => 'common',
        'icon' => 'mdi:lingerie',
        'description' => 'Celana ringan buat lari 5 km pertama.',
        'criteria' => 'Catat 1 lari sejauh 5 km atau lebih.',
    ],
    'accessory.celana_jarak' => [
        'name' => 'Celana Penjelajah',
        'slot' => 'celana',
        'rarity' => 'uncommon',
        'icon' => 'mdi:lingerie',
        'description' => 'Celana bawaan buat yang udah ngejar 10 km.',
        'criteria' => 'Catat 1 lari sejauh 10 km atau lebih.',
    ],
    'accessory.celana_split' => [
        'name' => 'Celana Negative Split',
        'slot' => 'celana',
        'rarity' => 'rare',
        'icon' => 'mdi:lingerie',
        'description' => 'Celana buat yang bisa negative split 3 kali.',
        'criteria' => 'Catat 3 lari negative split.',
    ],
    'accessory.celana_maraton' => [
        'name' => 'Celana Maraton',
        'slot' => 'celana',
        'rarity' => 'epic',
        'icon' => 'mdi:lingerie',
        'description' => 'Celana juara buat yang udah lari 21 km.',
        'criteria' => 'Catat 1 lari sejauh 21 km atau lebih.',
    ],

    // ── Sepatu (4) ─────────────────────────────────────────────────────
    'accessory.sepatu_basic' => [
        'name' => 'Sepatu Basic',
        'slot' => 'sepatu',
        'rarity' => 'common',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Sepatu dasar buat 10 lari pertama.',
        'criteria' => 'Catat 10 aktivitas lari.',
    ],
    'accessory.sepatu_cepat' => [
        'name' => 'Sepatu Cepat',
        'slot' => 'sepatu',
        'rarity' => 'uncommon',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Sepatu racing buat yang pernah lari pace 5:30/km.',
        'criteria' => 'Catat 1 lari dengan rata-rata pace di bawah 5:30/km.',
    ],
    'accessory.sepatu_tahan' => [
        'name' => 'Sepatu Tahan Banting',
        'slot' => 'sepatu',
        'rarity' => 'rare',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Sepatu kuat buat yang sering lari 10K+ dengan 5 lari.',
        'criteria' => 'Catat 5 lari sejauh 10 km atau lebih.',
    ],
    'accessory.sepatu_legendaris' => [
        'name' => 'Sepatu Legendaris',
        'slot' => 'sepatu',
        'rarity' => 'legendary',
        'icon' => 'mdi:shoe-sneaker',
        'description' => 'Sepatu emas buat yang total jarak udah 1000 km.',
        'criteria' => 'Akumulasi jarak 1000 km.',
    ],

    // ── Aura (4) ───────────────────────────────────────────────────────
    'accessory.aura_pemanasan' => [
        'name' => 'Aura Pemanasan',
        'slot' => 'aura',
        'rarity' => 'common',
        'icon' => 'mdi:blur',
        'description' => 'Aura hangat buat yang konsisten 2 minggu.',
        'criteria' => 'Lari di 2 minggu berturut-turut.',
    ],
    'accessory.aura_gerah' => [
        'name' => 'Aura Gerah',
        'slot' => 'aura',
        'rarity' => 'uncommon',
        'icon' => 'mdi:fire',
        'description' => 'Aura api buat yang nekat lari 3 kali pas gerah.',
        'criteria' => 'Selesaikan 3 lari saat suhu di atas 31°C.',
    ],
    'accessory.aura_tenang' => [
        'name' => 'Aura Tenang',
        'slot' => 'aura',
        'rarity' => 'rare',
        'icon' => 'mdi:blur',
        'description' => 'Aura adem buat yang bisa jaga HR Zone 2 di 5 lari.',
        'criteria' => 'Catat 5 lari di HR Zone 2 (bawah 70% HRmax).',
    ],
    'accessory.aura_jagoan' => [
        'name' => 'Aura Jagoan',
        'slot' => 'aura',
        'rarity' => 'epic',
        'icon' => 'mdi:blur',
        'description' => 'Aura kilat buat yang punya 3 kartu Legendaris.',
        'criteria' => 'Dapatkan 3 kartu Legendaris.',
    ],
];
