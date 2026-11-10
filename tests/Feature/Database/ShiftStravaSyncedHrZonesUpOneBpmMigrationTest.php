<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function runShiftStravaZonesMigration(): void
{
    $migration = require base_path('database/migrations/2026_11_10_000000_shift_strava_synced_hr_zones_up_one_bpm.php');
    $migration->up();
}

it('raises every Strava-synced bound by one bpm, keeping a resting Z1 floor and the open Z5 top', function (): void {
    $profile = RunnerProfile::factory()->create([
        'source' => 'strava',
        'resting_hr' => 55,
        'hr_zones' => [
            'Z1' => ['lo' => 55, 'hi' => 137],
            'Z2' => ['lo' => 137, 'hi' => 153],
            'Z3' => ['lo' => 153, 'hi' => 167],
            'Z4' => ['lo' => 167, 'hi' => 175],
            'Z5' => ['lo' => 175, 'hi' => 999],
        ],
    ]);
    Carbon::setTestNow('2026-11-10 12:00:00');

    runShiftStravaZonesMigration();

    $profile->refresh();
    expect($profile->hr_zones)->toEqual([
        'Z1' => ['lo' => 55, 'hi' => 138],
        'Z2' => ['lo' => 138, 'hi' => 154],
        'Z3' => ['lo' => 154, 'hi' => 168],
        'Z4' => ['lo' => 168, 'hi' => 176],
        'Z5' => ['lo' => 176, 'hi' => 999],
    ])
        ->and($profile->easyHrCapBpm())->toBe(154)
        ->and($profile->hr_zones_changed_at?->toDateTimeString())->toBe('2026-11-10 12:00:00');
});

it('raises a Strava Z1 floor that sits above the resting HR', function (): void {
    $profile = RunnerProfile::factory()->create([
        'source' => 'strava',
        'resting_hr' => 55,
        'hr_zones' => [
            'Z1' => ['lo' => 100, 'hi' => 120],
            'Z2' => ['lo' => 120, 'hi' => 140],
            'Z3' => ['lo' => 140, 'hi' => 160],
            'Z4' => ['lo' => 160, 'hi' => 175],
            'Z5' => ['lo' => 175, 'hi' => 999],
        ],
    ]);

    runShiftStravaZonesMigration();

    expect($profile->refresh()->hr_zones['Z1'])->toEqual(['lo' => 101, 'hi' => 121]);
});

it('leaves manual and observed zones untouched', function (string $source): void {
    $profile = RunnerProfile::factory()->create(['source' => $source]);
    $before = $profile->hr_zones;

    runShiftStravaZonesMigration();

    expect($profile->refresh()->hr_zones)->toEqual($before);
})->with(['manual', 'observed']);
