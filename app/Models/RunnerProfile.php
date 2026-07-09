<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RunnerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property int $max_hr
 * @property int $resting_hr
 * @property array<string, array{lo:int, hi:int}> $hr_zones
 * @property int $optimal_cadence_spm
 * @property Carbon|null $hr_zones_changed_at
 * @property Carbon|null $strava_zones_synced_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'source',
    'max_hr',
    'resting_hr',
    'hr_zones',
    'optimal_cadence_spm',
    'hr_zones_changed_at',
    'strava_zones_synced_at',
])]
class RunnerProfile extends Model
{
    /** @use HasFactory<RunnerProfileFactory> */
    use HasFactory;

    #[Override]
    protected static function booted(): void
    {
        static::saving(function (RunnerProfile $profile): void {
            if ($profile->isDirty(['max_hr', 'resting_hr', 'hr_zones'])) {
                $profile->hr_zones_changed_at = Carbon::now();
            }
        });

        // Keep the shared `hrZonesChangedAt` Inertia prop (cached in
        // HandleInertiaRequests) in step with the stored marker.
        static::saved(function (RunnerProfile $profile): void {
            Cache::forget("hr-zones-changed-at:{$profile->user_id}");
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'max_hr' => 'integer',
            'resting_hr' => 'integer',
            'hr_zones' => 'array',
            'optimal_cadence_spm' => 'integer',
            'hr_zones_changed_at' => 'datetime',
            'strava_zones_synced_at' => 'datetime',
        ];
    }
}
