<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property int $strava_athlete_id
 * @property int|null $user_id
 * @property int $credential_version
 * @property string $refresh_token
 */
#[Fillable(['strava_athlete_id', 'user_id', 'credential_version', 'refresh_token'])]
#[Hidden(['refresh_token'])]
class StravaGrantToken extends Model
{
    #[Override]
    protected $table = 'strava_grant_tokens';

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'strava_athlete_id' => 'integer',
            'user_id' => 'integer',
            'credential_version' => 'integer',
            'refresh_token' => 'encrypted',
        ];
    }
}
