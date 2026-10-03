<?php

declare(strict_types=1);

namespace App\Models\Analytics;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use App\Enums\StravaReadPriority;
use App\Enums\StravaReadSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property Carbon $read_at App-timezone time the Strava response was received
 * @property StravaReadSource $source
 * @property StravaReadPriority $priority
 * @property string $endpoint Safe endpoint category, never a raw path or activity ID
 * @property int $http_status
 * @property int|null $usage_15m
 * @property int|null $usage_daily
 */
#[Fillable(['read_at', 'source', 'priority', 'endpoint', 'http_status', 'usage_15m', 'usage_daily'])]
#[Connection('analytics')]
#[Table(name: 'strava_reads')]
#[WithoutTimestamps]
class StravaRead extends Model
{
    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'read_at' => 'datetime',
            'source' => StravaReadSource::class,
            'priority' => StravaReadPriority::class,
            'http_status' => 'integer',
            'usage_15m' => 'integer',
            'usage_daily' => 'integer',
        ];
    }
}
