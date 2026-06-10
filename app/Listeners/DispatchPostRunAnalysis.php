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

    private const int BACKFILL_SLOT_CACHE_TTL_HOURS = 2;

    public function __construct(
        private readonly AnalysisService $analysisService,
        private readonly WeeklyAggregator $weeklyAggregator,
    ) {}

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

        $this->analysisService->requestActivityGroup($activity, delaySeconds: $delaySec);
        $this->analysisService->requestBriefingGroup($user, $today, invalidate: true, delaySeconds: $delaySec);
        // BriefingMascotVoice was split out of the briefing group; dispatch it
        // independently so its own LLM call runs alongside the briefing group.
        $this->analysisService->request(
            subjectOrType: AnalysisType::BRIEFING_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::BriefingMascotVoice,
            discriminator: $today,
            delaySeconds: $delaySec,
            invalidate: true,
        );
        $this->analysisService->request(
            subjectOrType: AnalysisType::DAILY_GREETING_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::DailyGreeting,
            discriminator: $today,
            delaySeconds: $delaySec,
            invalidate: true,
        );
        $this->analysisService->request(
            subjectOrType: AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
            subjectId: $user->id,
            type: AnalysisType::TrendCaption,
            discriminator: $today,
            delaySeconds: $delaySec,
            invalidate: true,
        );

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
        $key = self::BACKFILL_SLOT_CACHE_PREFIX.$activity->user_id;
        $now = Carbon::now();

        $cached = Cache::get($key);
        $slotAt = ($cached instanceof CarbonInterface && $cached->gt($now)) ? $cached : $now->copy();
        $delaySec = (int) $now->diffInSeconds($slotAt, absolute: true);

        Cache::put($key, $slotAt->copy()->addSeconds($staggerSec), $now->copy()->addHours(self::BACKFILL_SLOT_CACHE_TTL_HOURS));

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
}
