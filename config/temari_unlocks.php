<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Temari Accessory Unlocks
|--------------------------------------------------------------------------
|
| Declarative map: unlock_key → metadata. Each accessory has a name, an
| icon (Iconify), a short description, and a criteria summary shown in
| locked silhouette state on the Profil koleksi grid.
|
*/

return [
    'accessory.medal_first_pr' => [
        'name' => 'Medali Pertama',
        'icon' => 'mdi:medal',
        'description' => 'Medali kuningan untuk PR pertama kamu.',
        'criteria' => 'Catat 1 PR di kategori apapun.',
    ],
    'accessory.medal_gold' => [
        'name' => 'Medali Emas',
        'icon' => 'mdi:medal-outline',
        'description' => 'Medali emas tipis untuk 5 PR total.',
        'criteria' => 'Catat 5 PR total.',
    ],
    'accessory.headband_legendaris' => [
        'name' => 'Headband Legendaris',
        'icon' => 'mdi:bandage',
        'description' => 'Headband khusus untuk kartu Legendaris.',
        'criteria' => 'Dapatkan 1 kartu Legendaris.',
    ],
    'accessory.headband_epik' => [
        'name' => 'Headband Luar Biasa',
        'icon' => 'mdi:bandage',
        'description' => 'Headband ungu untuk koleksi 3 kartu Luar Biasa.',
        'criteria' => 'Dapatkan 3 kartu Luar Biasa.',
    ],
    'accessory.weekly_streak_4' => [
        'name' => 'Pita Konsisten',
        'icon' => 'mdi:ribbon',
        'description' => 'Pita untuk 4 minggu beruntun lari.',
        'criteria' => 'Lari di 4 minggu berturut-turut.',
    ],
];
