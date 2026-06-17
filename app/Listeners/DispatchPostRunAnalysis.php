<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ActivityIngested;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Run\Metrics\WeeklyAggregator;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Owns the post-ingest AI analysis fan-out. Queued so it runs in its own job,
 * independent of the Strava ingest job's retry envelope. Each request is an
 * idempotent upsert, so a re-run never double-bills the LLM.
 */
class DispatchPostRunAnalysis implements ShouldQueue
{
    private const string BACKFILL_SLOT_CACHE_PREFIX = 'ai.backfill.next-slot:';

    private const string BACKFILL_SLOT_LOCK_PREFIX = 'ai.backfill.lock:';

    private const int BACKFILL_SLOT_CACHE_TTL_HOURS = 2;

    public function __construct(
        private readonly AnalysisService $analysisService,
        private readonly WeeklyAggregator $weeklyAggregator,
    ) {
    }

    public function handle(ActivityIngested $event): void
    {
        $activity = Activity::query()->with(['detail', 'user'])->find($event->activityId);
        if ($activity === null || $activity->detail === null) {
            return;
        }

        $user = $activity->user;
        $detail = $activity->detail;
        $today = Carbon::today()->toDateString();
        $delaySec = $this->backfillDelaySeconds($activity, $detail);
        $isToday = $detail->start_date_local?->toDateString() === $today;

        $this->analysisService->requestActivityGroup($activity, delaySeconds: $delaySec);

        // Daily cadence: when the ingested run is today's, refresh the whole
        // daily AI set so each block narrates with every run done so far today.
        // Backfill of a previous day leaves the Done LLM rows untouched (only
        // the free rule-based TrendCaption refreshes), so re-ingesting old days
        // never re-bills the briefing.
        $this->analysisService->requestBriefingGroup($user, $today, invalidate: $isToday, delaySeconds: $delaySec);
        // BriefingMascotVoice was split out of the briefing group; dispatch it
        // independently so its own LLM call runs alongside the briefing group.
        $dailyRowTypes = [
            AnalysisType::BriefingMascotVoice,
            AnalysisType::DailyGreeting,
            AnalysisType::TrendCaption,
        ];
        foreach ($dailyRowTypes as $type) {
            $this->analysisService->request(
                subjectOrType: $type->subjectType(),
                subjectId: $user->id,
                type: $type,
                discriminator: $today,
                delaySeconds: $delaySec,
                invalidate: $isToday || $type->isRuleBased(),
            );
        }

        if ($detail->start_date_local === null) {
            return;
        }
        $snapshot = $this->weeklyAggregator->rebuildForwardFrom($user, $detail->start_date_local);
        if ($snapshot !== null) {
            // Weekly cadence: regenerating the recap of a still-unfinished week
            // on every run was the single biggest LLM re-bill. The row is staged
            // Pending here; ai:weekly-recap narrates it once the week closes.
            // "Baca ulang" can still force a mid-week narration on demand.
            $this->analysisService->requestDeferred(
                WeeklySnapshot::class,
                $snapshot->id,
                AnalysisType::WeeklyRecap,
            );
        }
    }

    /**
     * Activities started more than `ai.backfill_threshold_hours` ago are
     * treated as backfill — their cascade gets staggered behind any other
     * backfilled cascades queued in the last 2 hours for this user. Fresh
     * runs (or activities with no start timestamp) bypass the cache and
     * dispatch immediately at delay=0.
     */
    private function backfillDelaySeconds(Activity $activity, ActivityDetail $detail): int
    {
        $startedAt = $detail->start_date_local;
        if ($startedAt === null) {
            return 0;
        }

        $thresholdHours = (int) config('ai.backfill_threshold_hours', 24);
        if (Carbon::now()->diffInHours($startedAt, absolute: true) < $thresholdHours) {
            return 0;
        }

        $staggerSec = max(1, (int) config('ai.backfill_stagger_seconds', 360));

        // The slot read-modify-write must be atomic per user: two concurrent
        // backfill listeners for the same user would otherwise read the same slot
        // and both dispatch at delay 0, collapsing the stagger into a burst. A
        // per-user lock serialises the reservation. On the (effectively
        // impossible) lock timeout, fall back to immediate dispatch rather than
        // blocking the queued listener.
        try {
            [$delaySec, $slotAt] = Cache::lock(self::BACKFILL_SLOT_LOCK_PREFIX.$activity->user_id, 10)
                ->block(3, fn (): array => $this->reserveBackfillSlot($activity->user_id, $staggerSec));
        } catch (LockTimeoutException) {
            Log::warning('ai.backfill.lock_timeout', ['user_id' => $activity->user_id]);

            return 0;
        }

        if ($delaySec > 0) {
            Log::info('ai.backfill.queued', [
                'activity_id' => $activity->id,
                'user_id' => $activity->user_id,
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
    private function reserveBackfillSlot(int $userId, int $staggerSec): array
    {
        $key = self::BACKFILL_SLOT_CACHE_PREFIX.$userId;
        $now = Carbon::now();

        $cached = Cache::get($key);
        $slotAt = ($cached instanceof CarbonInterface && $cached->gt($now)) ? $cached : $now->copy();
        $delaySec = (int) $now->diffInSeconds($slotAt, absolute: true);

        Cache::put($key, $slotAt->copy()->addSeconds($staggerSec), $now->copy()->addHours(self::BACKFILL_SLOT_CACHE_TTL_HOURS));

        return [$delaySec, $slotAt];
    }
}
