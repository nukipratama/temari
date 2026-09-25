<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Support\Carbon;
use App\Notifications\StravaDisconnectedNotification;
use App\Support\SharedPropCacheKey;
use Database\Factories\StravaConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
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
 * @property int $credential_version
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
    'credential_version',
])]
#[Hidden(['access_token', 'refresh_token'])]
class StravaConnection extends Model
{
    /** @use HasFactory<StravaConnectionFactory> */
    use HasFactory;

    /**
     * Keep the two shared Inertia props that read this row in step with it:
     * `stravaZoneScopeMissing` (granted scopes) and `stravaSync` (connection
     * presence and revoked state). Every writer goes through the model — the
     * OAuth connect/reconnect, the background token refresh and
     * `markRevoked()` — so a save is the one hook that catches all of them.
     */
    #[Override]
    protected static function booted(): void
    {
        static::saved(function (StravaConnection $connection): void {
            DB::afterCommit(function () use ($connection): void {
                SharedPropCacheKey::StravaZoneScopeMissing->forget($connection->user_id);
                SharedPropCacheKey::StravaSync->forget($connection->user_id);
            });
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

    /**
     * Whether the granted scopes include `profile:read_all`, which the Strava
     * HR-zone endpoint requires (see {@link ZoneFetcher}).
     */
    public function hasZoneScope(): bool
    {
        return str_contains((string) $this->scopes, 'profile:read_all');
    }

    /**
     * The one place a Strava grant dies, so it is also the one place the athlete
     * is told, once per revocation rather than once per failing call.
     * `$notify` is false only for an account deletion, whose cascade takes the
     * inbox row with it anyway.
     */
    public function markRevoked(bool $notify = true, ?int $expectedCredentialVersion = null): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        $revokedAt = Carbon::now();

        // Serialize this claim with reconnects so an older API failure cannot revoke new credentials.
        $connection = static::query()->getConnection()->transaction(function () use ($expectedCredentialVersion, $revokedAt): ?self {
            $connection = static::query()
                ->whereKey($this->getKey())
                ->whereNull('revoked_at')
                ->when($expectedCredentialVersion !== null, fn ($query) => $query->where('credential_version', $expectedCredentialVersion))
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return null;
            }

            $connection->update(['revoked_at' => $revokedAt]);

            return $connection;
        });

        if ($connection === null) {
            return false;
        }

        $this->setAttribute('revoked_at', $revokedAt);
        $this->syncOriginalAttribute('revoked_at');

        if ($notify) {
            $connection->user->notify(new StravaDisconnectedNotification($revokedAt));
        }

        // Purge this user's un-ingested stubs: the ingest drain only selects
        // activities whose connection is non-revoked, so stubs inserted before a
        // mid-sync 401 would otherwise sit orphaned forever. withStubs() opts out
        // of AnalyzedScope (which forces analyzed_at IS NOT NULL) — without it this
        // delete would match nothing.
        Activity::withStubs()
            ->where('user_id', $connection->user_id)
            ->whereNull('analyzed_at')
            ->delete();

        return true;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'strava_athlete_id' => 'integer',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'credential_version' => 'integer',
        ];
    }
}
