<?php

declare(strict_types=1);

/**
 * Default runner profile.
 *
 * v1 ships single-user with hardcoded defaults so the compute layer always
 * has sane HR/zone numbers without a settings UI. When per-user profiles
 * arrive (planned for v1.x), this file becomes the seed and individual
 * users override via `runner_profiles` rows.
 *
 * The zone ranges below are reasonable for the project owner (HR cap 180,
 * resting around the 50s). Don't tune these from any single run — these
 * are starting values, not personalized prescriptions.
 */
return [
    /**
     * Beats per minute at rest. Used as the floor in Edwards TRIMP's
     * HR-reserve calc and as the Z1 lower bound.
     */
    'resting_hr' => 55,

    /**
     * The aerobic HR ceiling used for zone math. Not the all-out max from
     * a single hard session — set this to the 95th-percentile of recent
     * max_heartrate (or just leave at 180 until a settings UI ships).
     */
    'max_hr' => 180,

    /**
     * HR zones inclusive lower / exclusive upper bounds (bpm).
     * Edwards TRIMP weights each minute in zone N by N.
     */
    'hr_zones' => [
        'Z1' => ['lo' => 116, 'hi' => 137],
        'Z2' => ['lo' => 138, 'hi' => 153],
        'Z3' => ['lo' => 154, 'hi' => 167],
        'Z4' => ['lo' => 168, 'hi' => 175],
        'Z5' => ['lo' => 176, 'hi' => 999],
    ],

    /**
     * Target running cadence in steps per minute. The single most-recommended
     * cadence band for efficient running is 170-185 spm.
     */
    'optimal_cadence_spm' => 170,
];
