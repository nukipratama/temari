<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

use function is_string;

/**
 * Computes the lifetime running totals (run count, total distance, first-run
 * date) shown on /kalender. The aggregate scans every analyzed run for the
 * user, so it is cached for a few minutes keyed by user id. The numbers only
 * move when a new run is ingested + analyzed (a comparatively rare event), so a
 * short time-based TTL keeps the page query-cheap without a stale-feeling UI.
 */
class LifetimeStats
{
    private const int CACHE_TTL_SECONDS = 300;

    /**
     * @return array{total_runs: int, total_km: float, first_run_at: string|null}
     */
    public function forUser(User $user): array
    {
        return Cache::remember(
            self::cacheKey($user->id),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->compute($user),
        );
    }

    public static function cacheKey(int $userId): string
    {
        return "lifetime-stats:{$userId}";
    }

    /**
     * @return array{total_runs: int, total_km: float, first_run_at: string|null}
     */
    private function compute(User $user): array
    {
        $totalRuns = $user->activities()->whereNotNull('analyzed_at')->count();

        $aggregates = ActivityDetail::query()
            ->whereHas(
                'activity',
                fn ($q) => $q->where('user_id', $user->id)->whereNotNull('analyzed_at'),
            )
            ->selectRaw('SUM(distance) AS total_distance, MIN(start_date_local) AS first_run_at')
            ->first();

        $totalDistanceMeters = (float) ($aggregates?->getAttribute('total_distance') ?? 0);
        $firstRunAt = $aggregates?->getAttribute('first_run_at');

        return [
            'total_runs' => $totalRuns,
            'total_km' => round($totalDistanceMeters / 1000, 1),
            'first_run_at' => is_string($firstRunAt) ? $firstRunAt : $firstRunAt?->toIso8601String(),
        ];
    }
}
