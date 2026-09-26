<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StravaGrantEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property int $strava_athlete_id
 * @property int|null $user_id
 * @property int $credential_version
 * @property StravaGrantEventType $event
 * @property string|null $error
 * @property Carbon $created_at
 */
#[Fillable(['strava_athlete_id', 'user_id', 'credential_version', 'event', 'error', 'created_at'])]
class StravaGrantEvent extends Model
{
    #[Override]
    public $timestamps = false;

    #[Override]
    protected $table = 'strava_grant_events';

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'strava_athlete_id' => 'integer',
            'user_id' => 'integer',
            'credential_version' => 'integer',
            'event' => StravaGrantEventType::class,
            'created_at' => 'datetime',
        ];
    }
}
