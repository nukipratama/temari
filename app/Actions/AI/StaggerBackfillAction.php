<?php

declare(strict_types=1);

namespace App\Actions\AI;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The single atomic per-user slot reservation every backfill dispatch path
 * draws from — the initial ingest kickoff, the per-activity/weekly/monthly
 * chain-advance hooks, all invoke this so they can never schedule two
 * dispatches for the same user's cascade around the same time.
 */
class StaggerBackfillAction
{
    private const string SLOT_CACHE_PREFIX = 'ai.backfill.next-slot:';

    private const string SLOT_LOCK_PREFIX = 'ai.backfill.lock:';

    private const int SLOT_CACHE_TTL_HOURS = 2;

    /**
     * A backfilled cascade gets staggered behind any other backfilled cascades
     * queued in the last 2 hours for this user.
     */
    public function __invoke(int $userId): int
    {
        $staggerSec = max(1, (int) config('ai.backfill_stagger_seconds', 360));

        try {
            [$delaySec, $slotAt] = Cache::lock(self::SLOT_LOCK_PREFIX.$userId, 10)
                ->block(3, fn (): array => $this->reserveSlot($userId, $staggerSec));
        } catch (LockTimeoutException) {
            Log::warning('ai.backfill.lock_timeout', ['user_id' => $userId]);

            return 0;
        }

        if ($delaySec > 0) {
            Log::info('ai.backfill.queued', [
                'user_id' => $userId,
                'delay_sec' => $delaySec,
                'slot_at' => $slotAt->toIso8601String(),
            ]);
        }

        return $delaySec;
    }

    /**
     * Reserve the next staggered slot for a user under the held lock: read the
     * current slot, take it (or now if none/expired), and advance the stored
     * slot by the stagger window.
     *
     * @return array{0: int, 1: CarbonInterface}  the delay in seconds and the reserved slot
     */
    private function reserveSlot(int $userId, int $staggerSec): array
    {
        $key = self::SLOT_CACHE_PREFIX.$userId;
        $now = Carbon::now();

        $cached = Cache::get($key);
        $slotAt = ($cached instanceof CarbonInterface && $cached->gt($now)) ? $cached : $now->copy();
        $delaySec = (int) $now->diffInSeconds($slotAt, absolute: true);

        Cache::put($key, $slotAt->copy()->addSeconds($staggerSec), $now->copy()->addHours(self::SLOT_CACHE_TTL_HOURS));

        return [$delaySec, $slotAt];
    }
}
