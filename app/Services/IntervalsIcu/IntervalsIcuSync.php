<?php

declare(strict_types=1);

namespace App\Services\IntervalsIcu;

use App\Enums\IntervalsIcuSyncStatus;
use App\Enums\PlannedSessionStatus;
use App\Models\IntervalsIcuConnection;
use App\Models\User;
use App\Notifications\IntervalsIcuDisconnectedNotification;
use App\Services\IntervalsIcu\Exceptions\IntervalsIcuRejectedException;
use App\Services\IntervalsIcu\Exceptions\IntervalsIcuUnreachableException;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Plan\RecommendationHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;

/**
 * Keeps today's workout on the athlete's intervals.icu calendar matching
 * today as Today shows it: sent once, updated in place when it changes,
 * removed when today becomes a rest day, is skipped or cannot be sent.
 * Once today's run is credited the workout stays as it was sent, and
 * earlier days' workouts stay where they are.
 *
 * @phpstan-import-type Day from IntervalsIcuWorkoutMapper
 * @phpstan-import-type HeartRate from IntervalsIcuWorkoutMapper
 */
final readonly class IntervalsIcuSync
{
    public function __construct(
        private CurrentWeekPlanBuilder $weekPlan,
        private IntervalsIcuClient $client,
        private RecommendationHistory $history,
    ) {
    }

    public function sync(IntervalsIcuConnection $connection, Carbon $today): void
    {
        if ($connection->isBroken()) {
            return;
        }

        $date = $today->toDateString();
        if ($connection->synced_on?->toDateString() !== $date) {
            $connection->fill(['synced_on' => $date, 'sync_status' => null, 'event_id' => null, 'payload_hash' => null]);
        }

        try {
            $this->reconcile($connection, $today);
        } catch (IntervalsIcuRejectedException) {
            $this->markBroken($connection);
        } catch (IntervalsIcuUnreachableException) {
            $connection->update(['sync_status' => IntervalsIcuSyncStatus::Unreachable]);
            Pulse::record('intervals_icu_sync', 'failed')->count();
        }
    }

    /**
     * Removes today's workout, as when the athlete disconnects. Earlier days'
     * workouts and anything the athlete made themselves stay.
     */
    public function removeToday(IntervalsIcuConnection $connection, Carbon $today): void
    {
        if ($connection->event_id === null || $connection->synced_on?->toDateString() !== $today->toDateString()) {
            return;
        }

        $this->client->deleteEvent($connection, $connection->event_id);
        Pulse::record('intervals_icu_sync', 'deleted')->count();
    }

    /**
     * {@see self::removeToday()} for a caller that must carry on whatever
     * intervals.icu answers: a broken key is skipped, a failure is logged.
     */
    public function removeTodayBestEffort(IntervalsIcuConnection $connection, Carbon $today): void
    {
        if ($connection->isBroken()) {
            return;
        }

        try {
            $this->removeToday($connection, $today);
        } catch (IntervalsIcuRejectedException|IntervalsIcuUnreachableException $e) {
            Log::warning('intervals.icu could not remove the workout', ['user_id' => $connection->user_id, 'exception' => $e::class]);
        }
    }

    private function reconcile(IntervalsIcuConnection $connection, Carbon $today): void
    {
        $shown = $this->today($connection->user, $today);
        if ($shown !== null && self::alreadyRun($shown)) {
            return;
        }

        $day = self::runOf($shown);
        $workout = null;
        $status = null;

        if ($day !== null) {
            $needsHeartRate = IntervalsIcuWorkoutMapper::needsHeartRate($day);
            $heartRate = $needsHeartRate ? $this->heartRate($connection) : null;
            $workout = IntervalsIcuWorkoutMapper::map($day, $heartRate);
            if ($needsHeartRate && $heartRate === null) {
                $status = IntervalsIcuSyncStatus::NoThresholdHeartRate;
            }
        }

        if (!$workout instanceof IntervalsIcuWorkout) {
            $this->removeToday($connection, $today);
            $connection->fill(['sync_status' => $status, 'event_id' => null, 'payload_hash' => null])->save();

            return;
        }

        $hash = hash('sha256', $workout->name."\n".$workout->description);
        $date = $today->toDateString();

        $sent = false;
        if ($connection->event_id !== null && $hash !== $connection->payload_hash) {
            if ($this->client->updateEvent($connection, $connection->event_id, $date, $workout)) {
                Pulse::record('intervals_icu_sync', 'updated')->count();
                $sent = true;
            } else {
                $connection->event_id = null;
            }
        }

        if ($connection->event_id === null) {
            $connection->event_id = $this->client->createEvent($connection, $date, $workout);
            Pulse::record('intervals_icu_sync', 'created')->count();
            $sent = true;
        }

        $connection->fill(['sync_status' => IntervalsIcuSyncStatus::Sent, 'payload_hash' => $hash])->save();
        if ($sent && $shown !== null) {
            $this->recordShown($shown);
        }
    }

    /**
     * A day that keeps its prescription beside an advice note sends the prescription, which its token does not carry.
     *
     * @param  array<string, mixed>  $shown
     */
    private function recordShown(array $shown): void
    {
        $token = $shown['recommendation_token'] ?? null;
        if (($shown['advice_note'] ?? null) !== null || ! is_string($token)) {
            return;
        }

        $advice = $this->history->advice($token);
        $revision = $this->history->record($advice['user_id'], $advice['date'], $advice['original'], $advice['effective'], $advice['policy_version']);
        $this->history->shown($revision, (string) Str::uuid());
    }

    /**
     * Today as Today shows it, or null when no plan covers today.
     *
     * @return array<string, mixed>|null
     */
    private function today(User $user, Carbon $today): ?array
    {
        $date = $today->toDateString();
        foreach ($this->weekPlan->forUser($user, $today)['days'] ?? [] as $day) {
            if ($day['date'] === $date) {
                return $day;
            }
        }

        return null;
    }

    /**
     * Once today's run is credited the workout on the watch is history, so a
     * later change to the day (the run itself feeding the plan) leaves it be.
     *
     * @param  array<string, mixed>  $shown
     */
    private static function alreadyRun(array $shown): bool
    {
        return $shown['session_type'] !== 'rest'
            && is_string($shown['status'])
            && PlannedSessionStatus::from($shown['status'])->isCredited();
    }

    /**
     * Today's run to send, or null when there is none: no plan, a rest day,
     * or a skipped day.
     *
     * @param  array<string, mixed>|null  $shown
     * @return Day|null
     */
    private static function runOf(?array $shown): ?array
    {
        if ($shown === null || $shown['session_type'] === 'rest' || $shown['skipped'] === true) {
            return null;
        }

        /** @var Day $shown */
        return $shown;
    }

    /**
     * The athlete's temari zones against intervals.icu's run threshold, read
     * each time so a threshold they change there is used on the next sync.
     *
     * @return HeartRate|null
     */
    private function heartRate(IntervalsIcuConnection $connection): ?array
    {
        $threshold = $this->client->runThresholdHeartRate($connection);
        if ($threshold === null) {
            return null;
        }

        $profile = $connection->user->hrProfile();

        return ['threshold_bpm' => $threshold, 'max_hr' => $profile['max_hr'], 'hr_zones' => $profile['hr_zones']];
    }

    private function markBroken(IntervalsIcuConnection $connection): void
    {
        $brokenAt = Carbon::now();
        $marked = IntervalsIcuConnection::query()
            ->whereKey($connection->id)
            ->whereNull('broken_at')
            ->update(['broken_at' => $brokenAt]);

        if ($marked === 1) {
            Pulse::record('intervals_icu_sync', 'broken')->count();
            $connection->user->notify(new IntervalsIcuDisconnectedNotification($brokenAt));
        }
    }
}
