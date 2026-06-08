<?php

declare(strict_types=1);

// Single-user defaults; replaced by per-user `runner_profiles` rows in v1.x.
return [
    'resting_hr' => 55,
    'max_hr' => 180,

    // Inclusive lo / exclusive hi (bpm). Each zone's hi equals the next zone's
    // lo so the bands are gapless: a boundary bpm always lands in exactly one
    // zone. Edwards TRIMP weights each minute in zone N by N.
    'hr_zones' => [
        'Z1' => ['lo' => 116, 'hi' => 138],
        'Z2' => ['lo' => 138, 'hi' => 154],
        'Z3' => ['lo' => 154, 'hi' => 168],
        'Z4' => ['lo' => 168, 'hi' => 176],
        'Z5' => ['lo' => 176, 'hi' => 999],
    ],

    'optimal_cadence_spm' => 170,
];
