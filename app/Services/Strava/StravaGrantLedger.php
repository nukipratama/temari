<?php

declare(strict_types=1);

namespace App\Services\Strava;

use stdClass;
use Illuminate\Support\Collection;
use App\Enums\StravaGrantEventType;
use App\Enums\StravaGrantReleaseStatus;
use App\Models\StravaConnection;
use App\Models\StravaGrantEvent;
use App\Models\StravaGrantToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

final class StravaGrantLedger
{
    public function nextCredentialVersion(int $stravaAthleteId, ?int $connectionVersion = null): int
    {
        $eventVersion = StravaGrantEvent::query()
            ->where('strava_athlete_id', $stravaAthleteId)
            ->max('credential_version');
        $tokenVersion = StravaGrantToken::query()
            ->where('strava_athlete_id', $stravaAthleteId)
            ->value('credential_version');

        return max(
            $connectionVersion ?? -1,
            (int) ($eventVersion ?? -1),
            (int) ($tokenVersion ?? -1),
        ) + 1;
    }

    public function hasHistory(int $stravaAthleteId): bool
    {
        return StravaGrantEvent::query()->where('strava_athlete_id', $stravaAthleteId)->exists();
    }

    public function recordGrant(
        int $stravaAthleteId,
        ?int $userId,
        int $credentialVersion,
        string $refreshToken,
        StravaGrantEventType $event,
    ): void {
        DB::transaction(function () use ($stravaAthleteId, $userId, $credentialVersion, $refreshToken, $event): void {
            $current = StravaGrantToken::query()
                ->where('strava_athlete_id', $stravaAthleteId)
                ->lockForUpdate()
                ->first();

            if ($current !== null && $credentialVersion <= $current->credential_version) {
                throw new LogicException('A new Strava grant must advance its credential version.');
            }

            StravaGrantToken::query()->updateOrCreate(
                ['strava_athlete_id' => $stravaAthleteId],
                [
                    'user_id' => $userId,
                    'credential_version' => $credentialVersion,
                    'refresh_token' => $refreshToken,
                ],
            );

            $this->appendEvent($stravaAthleteId, $userId, $credentialVersion, $event);
        });
    }

    public function persistConnectionRefresh(
        StravaConnection $connection,
        int $credentialVersion,
        string $accessToken,
        string $refreshToken,
        Carbon $expiresAt,
    ): bool {
        return DB::transaction(function () use ($connection, $credentialVersion, $accessToken, $refreshToken, $expiresAt): bool {
            $attributes = $connection->newInstance([
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $expiresAt,
            ])->getAttributes();

            $updated = $connection->newQuery()
                ->whereKey($connection->getKey())
                ->where('credential_version', $credentialVersion)
                ->update($attributes);

            if ($updated === 0) {
                return false;
            }

            $grant = StravaGrantToken::query()
                ->where('strava_athlete_id', $connection->strava_athlete_id)
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                StravaGrantToken::query()->create([
                    'strava_athlete_id' => $connection->strava_athlete_id,
                    'user_id' => $connection->user_id,
                    'credential_version' => $credentialVersion,
                    'refresh_token' => $refreshToken,
                ]);

                $event = StravaGrantEvent::query()
                    ->where('strava_athlete_id', $connection->strava_athlete_id)
                    ->exists() ? StravaGrantEventType::Reconnected : StravaGrantEventType::Granted;

                $this->appendEvent(
                    $connection->strava_athlete_id,
                    $connection->user_id,
                    $credentialVersion,
                    $event,
                );

                return true;
            }

            if ($grant->credential_version !== $credentialVersion) {
                $message = $grant->credential_version > $credentialVersion
                    ? 'Skipped mirroring a stale Strava refresh because a newer grant exists.'
                    : 'Skipped mirroring a Strava refresh because the grant mirror is behind the connection.';

                Log::warning($message, [
                    'connection_id' => $connection->getKey(),
                    'connection_credential_version' => $credentialVersion,
                    'grant_credential_version' => $grant->credential_version,
                ]);

                return true;
            }

            $grant->refresh_token = $refreshToken;
            $grant->save();

            return true;
        });
    }

    public function persistGrantRefresh(int $stravaAthleteId, int $credentialVersion, string $refreshToken): bool
    {
        $attributes = new StravaGrantToken()->newInstance(['refresh_token' => $refreshToken])->getAttributes();

        return StravaGrantToken::query()
            ->where('strava_athlete_id', $stravaAthleteId)
            ->where('credential_version', $credentialVersion)
            ->update($attributes) === 1;
    }

    public function recordRejected(int $stravaAthleteId, int $userId, int $credentialVersion, ?string $error = null): bool
    {
        return DB::transaction(function () use ($stravaAthleteId, $userId, $credentialVersion, $error): bool {
            $grant = StravaGrantToken::query()
                ->where('strava_athlete_id', $stravaAthleteId)
                ->where('credential_version', $credentialVersion)
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                return false;
            }

            $this->appendEvent($stravaAthleteId, $userId, $credentialVersion, StravaGrantEventType::Rejected, $error);
            $grant->delete();

            return true;
        });
    }

    public function recordReleaseOutcome(
        StravaGrantToken $grant,
        StravaGrantReleaseResult $result,
        bool $forced,
    ): bool {
        if ($result->status === StravaGrantReleaseStatus::Stale) {
            return false;
        }

        return DB::transaction(function () use ($grant, $result, $forced): bool {
            $current = StravaGrantToken::query()
                ->where('strava_athlete_id', $grant->strava_athlete_id)
                ->where('credential_version', $grant->credential_version)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return false;
            }

            $event = match ($result->status) {
                StravaGrantReleaseStatus::Released => $forced ? StravaGrantEventType::ForceReleased : StravaGrantEventType::Released,
                StravaGrantReleaseStatus::Rejected => StravaGrantEventType::Rejected,
                StravaGrantReleaseStatus::Failed => StravaGrantEventType::ReleaseFailed,
            };

            $this->appendEvent(
                $current->strava_athlete_id,
                $current->user_id,
                $current->credential_version,
                $event,
                $result->error,
            );

            if ($result->status !== StravaGrantReleaseStatus::Failed) {
                $current->delete();
            }

            return true;
        });
    }

    /** @return Builder<StravaGrantToken> */
    public function currentGrantTokens(): Builder
    {
        return StravaGrantToken::query()->whereIn('strava_athlete_id', $this->openAthleteIds());
    }

    /** @return Builder<StravaGrantToken> */
    public function orphanGrantTokens(): Builder
    {
        return $this->currentGrantTokens()->whereNotExists(function (QueryBuilder $query): void {
            $query->selectRaw('1')
                ->from('strava_connections')
                ->whereColumn('strava_connections.strava_athlete_id', 'strava_grant_tokens.strava_athlete_id')
                ->whereNull('strava_connections.revoked_at');
        });
    }

    /** @return Collection<int, stdClass> */
    public function holderRows(): Collection
    {
        $grantedAt = DB::table('strava_grant_events as grant_events')
            ->selectRaw('MAX(grant_events.created_at)')
            ->whereColumn('grant_events.strava_athlete_id', 'tokens.strava_athlete_id')
            ->whereColumn('grant_events.credential_version', 'tokens.credential_version')
            ->whereIn('grant_events.event', [
                StravaGrantEventType::Granted->value,
                StravaGrantEventType::Reconnected->value,
                StravaGrantEventType::RefusedInMaintenance->value,
            ]);
        $releaseAttempts = DB::table('strava_grant_events as attempts')
            ->selectRaw('COUNT(*)')
            ->whereColumn('attempts.strava_athlete_id', 'tokens.strava_athlete_id')
            ->whereColumn('attempts.credential_version', 'tokens.credential_version')
            ->whereIn('attempts.event', StravaGrantEventType::releaseAttemptValues());
        $lastError = DB::table('strava_grant_events as failures')
            ->select('failures.error')
            ->whereColumn('failures.strava_athlete_id', 'tokens.strava_athlete_id')
            ->whereColumn('failures.credential_version', 'tokens.credential_version')
            ->where('failures.event', StravaGrantEventType::ReleaseFailed->value)
            ->orderByDesc('failures.id')
            ->limit(1);

        return DB::table('strava_grant_tokens as tokens')
            ->whereIn('tokens.strava_athlete_id', $this->openAthleteIds())
            ->leftJoin('strava_connections as connections', 'connections.strava_athlete_id', '=', 'tokens.strava_athlete_id')
            ->select([
                'tokens.strava_athlete_id',
                'tokens.user_id',
                'tokens.credential_version',
                'connections.id as connection_id',
                'connections.revoked_at as connection_revoked_at',
            ])
            ->selectSub($grantedAt, 'granted_at')
            ->selectSub($releaseAttempts, 'release_attempts')
            ->selectSub($lastError, 'last_error')
            ->orderBy('tokens.strava_athlete_id')
            ->get();
    }

    private function appendEvent(
        int $stravaAthleteId,
        ?int $userId,
        int $credentialVersion,
        StravaGrantEventType $event,
        ?string $error = null,
    ): void {
        StravaGrantEvent::query()->create([
            'strava_athlete_id' => $stravaAthleteId,
            'user_id' => $userId,
            'credential_version' => $credentialVersion,
            'event' => $event,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
            'created_at' => now(),
        ]);
    }

    private function openAthleteIds(): QueryBuilder
    {
        $latestEventIds = DB::table('strava_grant_events')
            ->selectRaw('MAX(id)')
            ->groupBy('strava_athlete_id');

        return DB::table('strava_grant_events as current_events')
            ->whereIn('current_events.id', $latestEventIds)
            ->whereIn('current_events.event', StravaGrantEventType::openValues())
            ->select('current_events.strava_athlete_id');
    }
}
