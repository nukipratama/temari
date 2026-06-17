<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Support\Carbon;
use Database\Factories\StravaConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property int $strava_athlete_id
 * @property string $access_token
 * @property string $refresh_token
 * @property Carbon $token_expires_at
 * @property string $scopes
 * @property Carbon|null $revoked_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'strava_athlete_id',
    'access_token',
    'refresh_token',
    'token_expires_at',
    'scopes',
    'revoked_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class StravaConnection extends Model
{
    /** @use HasFactory<StravaConnectionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<StravaConnection>  $query
     * @return Builder<StravaConnection>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function markRevoked(): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        $this->update(['revoked_at' => Carbon::now()]);

        // Purge this user's un-ingested stubs: the ingest drain only selects
        // activities whose connection is non-revoked, so stubs inserted before a
        // mid-sync 401 would otherwise sit orphaned forever. withStubs() opts out
        // of AnalyzedScope (which forces analyzed_at IS NOT NULL) — without it this
        // delete would match nothing.
        Activity::withStubs()
            ->where('user_id', $this->user_id)
            ->whereNull('analyzed_at')
            ->delete();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'strava_athlete_id' => 'integer',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
