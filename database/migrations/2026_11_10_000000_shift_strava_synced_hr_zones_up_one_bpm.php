<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use Illuminate\Database\Migrations\Migration;

/**
 * Strava-synced zones were stored with Strava's exclusive range minimum as the
 * inclusive lower bound, one bpm low at every boundary. This raises each bound
 * by one, except a Z1 floor already lifted to the resting HR and Z5's open top.
 *
 * down() is a documented no-op: the shifted bounds are the ones Strava shows.
 */
return new class () extends Migration {
    private const int OPEN_TOP = 999;

    public function up(): void
    {
        RunnerProfile::query()->where('source', 'strava')->each(function (RunnerProfile $profile): void {
            $zones = [];
            foreach ($profile->hr_zones as $key => $zone) {
                $keepsFloor = $key === 'Z1' && $zone['lo'] === $profile->resting_hr;
                $zones[$key] = [
                    'lo' => $keepsFloor ? $zone['lo'] : $zone['lo'] + 1,
                    'hi' => $zone['hi'] === self::OPEN_TOP ? self::OPEN_TOP : $zone['hi'] + 1,
                ];
            }

            $profile->update(['hr_zones' => $zones]);
        });
    }

    public function down(): void
    {
    }
};
