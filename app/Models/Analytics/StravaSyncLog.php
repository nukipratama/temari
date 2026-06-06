<?php

declare(strict_types=1);

namespace App\Models\Analytics;

use Override;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $status
 * @property int $activities_synced
 * @property int $api_calls_used
 * @property int|null $rate_limit_15min_remaining
 * @property int|null $rate_limit_daily_remaining
 * @property string|null $error_message
 * @property Carbon $synced_at
 */
#[Fillable(['user_id', 'status', 'activities_synced', 'api_calls_used', 'rate_limit_15min_remaining', 'rate_limit_daily_remaining', 'error_message', 'synced_at'])]
class StravaSyncLog extends Model
{
    public $timestamps = false;

    protected $connection = 'analytics';

    protected $table = 'strava_sync_logs';

    /**
     * Central factory for writing sync-log rows. All call sites should go
     * through here so the column shape stays in one place.
     *
     * @param  array{'15min': int, 'daily': int}|null  $rateLimits
     */
    public static function log(
        int $userId,
        string $status,
        int $activitiesSynced = 0,
        int $apiCallsUsed = 0,
        ?string $error = null,
        ?array $rateLimits = null,
    ): self {
        return self::create([
            'user_id' => $userId,
            'status' => $status,
            'activities_synced' => $activitiesSynced,
            'api_calls_used' => $apiCallsUsed,
            'rate_limit_15min_remaining' => $rateLimits['15min'] ?? null,
            'rate_limit_daily_remaining' => $rateLimits['daily'] ?? null,
            'error_message' => $error,
            'synced_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
