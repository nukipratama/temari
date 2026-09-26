<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use App\Notifications\StravaDisconnectedNotification;
use App\Services\Strava\StravaGrantLedger;
use App\Support\SharedPropCacheKey;
use Database\Factories\StravaConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
     * Keep `stravaZoneScopeMissing` and `stravaSync` in step with OAuth saves and
     * revocations; token refresh changes neither cached fact and uses a version-checked write.
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
     * The one place a Strava connection is locally revoked, so it is also the
     * one place the athlete is told, once per revocation rather than per failure.
     * `$notify` is false only for an account deletion, whose cascade takes the
     * inbox row with it anyway.
     */
    public function markRevoked(
        bool $notify = true,
        ?int $expectedCredentialVersion = null,
        bool $stravaRejected = false,
    ): bool {
        if ($this->revoked_at !== null) {
            return false;
        }

        $revokedAt = Carbon::now();

        // Serialize this claim with reconnects so an older API failure cannot revoke new credentials.
        $connection = static::query()->getConnection()->transaction(function () use ($expectedCredentialVersion, $revokedAt, $stravaRejected): ?self {
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

            if ($stravaRejected) {
                app(StravaGrantLedger::class)->recordRejected(
                    $connection->strava_athlete_id,
                    $connection->user_id,
                    $connection->credential_version,
                );
            }

            // Purge un-ingested stubs; the drain skips revoked connections, and
            // withStubs bypasses the analyzed-only scope. Keep it atomic so a
            // reconnect's catch-up stubs cannot be purged afterward.
            Activity::withStubs()
                ->where('user_id', $connection->user_id)
                ->whereNull('analyzed_at')
                ->delete();

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
