<?php

declare(strict_types=1);

return [
    'admin_strava_ids' => array_values(array_filter(array_map(
        fn ($value): ?int => ctype_digit(trim((string) $value)) ? (int) trim((string) $value) : null,
        explode(',', (string) env('DEV_ADMIN_STRAVA_IDS', '')),
    ))),
];
