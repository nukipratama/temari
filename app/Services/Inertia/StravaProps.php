<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\Activity;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Closure;

/**
 * The Strava-connection family of shared props: how the sync is going, whether
 * the connection still lacks the zone scope, and when the heart-rate zones that
 * connection feeds last moved.
 *
 * Every prop is returned as a closure, so Inertia skips the work entirely on a
 * partial reload that did not ask for that key.
 */
final readonly class StravaProps
{
    /**
     * @return array<string, Closure>
     */
    public function forUser(?User $user): array
    {
        return [
            'stravaSync' => fn () => $this->stravaSyncFor($user),
            'hrZonesChangedAt' => fn () => $this->hrZonesChangedAtFor($user),
            'stravaZoneScopeMissing' => fn (): bool => $this->stravaZoneScopeMissingFor($user),
        ];
    }

    /**
     * True when the auth user has a live (non-revoked) Strava connection whose
     * granted scopes lack `profile:read_all` — the zone-sync gate needs that
     * scope, so this drives the reconnect banner rather than provoking a 403.
     * The demo user never syncs zones from Strava, so it is never nudged.
     */
    private function stravaZoneScopeMissingFor(?User $user): bool
    {
        if ($user === null || $user->is_demo) {
            return false;
        }

        return SharedPropCacheKey::StravaZoneScopeMissing->remember(
            $user->id,
            function () use ($user): bool {
                $connection = $user->stravaConnection;

                if ($connection === null || $connection->isRevoked()) {
                    return false;
                }

                return ! $connection->hasZoneScope();
            },
        );
    }

    /**
     * ISO-8601 timestamp of the auth user's last heart-rate-zone change, or null
     * when there is no runner profile or it has never changed. The front end
     * compares this against each analysis block's `generated_at` to flag blocks
     * computed with stale zones.
     */
    private function hrZonesChangedAtFor(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        // Wrap in an array so a null marker (the common no-custom-profile case)
        // is still cached; Cache::remember treats a bare null as a miss and
        // would re-query every request.
        return SharedPropCacheKey::HrZonesChangedAt->remember(
            $user->id,
            fn (): array => ['at' => $user->runnerProfile?->hr_zones_changed_at?->toIso8601String()],
        )['at'];
    }

    /**
     * @return array{state: string, last_synced_at: string|null}
     */
    private function stravaSyncFor(?User $user): array
    {
        if ($user === null) {
            return ['state' => 'disconnected', 'last_synced_at' => null];
        }

        return SharedPropCacheKey::StravaSync->remember(
            $user->id,
            fn (): array => $this->computeStravaSyncFor($user),
        );
    }

    /**
     * Resolve the honest connection state the UI branches on (the client derives
     * "connected" from this, so it is not shipped separately):
     *  - `disconnected`: no Strava connection at all.
     *  - `revoked`: connection exists but was revoked (token rejected / deauthorized).
     *  - `syncing`: connected, but no analyzed run has landed yet (backfill in flight).
     *  - `ready`: at least one analyzed run is on the dashboard.
     *
     * @return array{state: string, last_synced_at: string|null}
     */
    private function computeStravaSyncFor(User $user): array
    {
        $connection = $user->stravaConnection;
        if ($connection === null) {
            return ['state' => 'disconnected', 'last_synced_at' => null];
        }

        if ($connection->isRevoked()) {
            return ['state' => 'revoked', 'last_synced_at' => null];
        }

        // "Last synced" reflects the most recent Strava pull, so it counts
        // stubs too (a just-synced run that has not been ingested yet still
        // means we synced). withStubs() opts out of the analyzed-only scope.
        $latest = Activity::query()
            ->withStubs()
            ->where('user_id', $user->id)
            ->whereNotNull('fetched_at')
            ->orderByDesc('fetched_at')
            ->value('fetched_at');

        // "ready" once at least one run is fully ingested; the scope already
        // restricts to analyzed rows, so exists() answers that directly.
        $hasAnalyzed = Activity::query()
            ->where('user_id', $user->id)
            ->exists();

        return [
            'state' => $hasAnalyzed ? 'ready' : 'syncing',
            'last_synced_at' => $latest?->toIso8601String(),
        ];
    }
}
