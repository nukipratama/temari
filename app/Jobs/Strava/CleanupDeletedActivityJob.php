<?php

declare(strict_types=1);

namespace App\Jobs\Strava;

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes a Strava-removed activity and heals the artifacts that don't cascade:
 * the FK cascade drops detail / streams / card / post-run storyline, but the
 * weekly snapshot is recomputed from a separate aggregate, PRs are only ever
 * lowered (so a deleted run's record lingers), and the polymorphic Analysis rows
 * have no FK. Run from the delete webhook so the webhook itself still acks fast.
 */
class CleanupDeletedActivityJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly int $stravaActivityId,
    ) {
    }

    public function handle(WeeklyAggregator $weekly, PersonalRecords $personalRecords): void
    {
        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $activity = Activity::query()
            ->withStubs()
            ->where('user_id', $user->id)
            ->where('strava_external_id', $this->stravaActivityId)
            ->with(['detail', 'runCard'])
            ->first();
        if ($activity === null) {
            return;
        }

        $weekAnchor = $activity->detail?->start_date_local;
        $localId = $activity->id;
        // The card cascades on delete, but its CardFlavor analysis (keyed by the
        // card id, no FK) does not — capture the id now to purge it below.
        $cardId = $activity->runCard?->id;

        DB::transaction(function () use ($activity, $weekAnchor, $user, $weekly, $personalRecords, $localId, $cardId): void {
            // Cascades detail / stream / card / post-run storyline via FK.
            $activity->delete();

            if ($weekAnchor !== null) {
                $rebuilt = $weekly->rebuildForwardFrom($user, $weekAnchor);

                // rebuildForwardFrom no-ops on an empty lookback window (e.g. the
                // user's only run was the deleted one), which would leave a stale
                // snapshot claiming runs that no longer exist. Drop the now-empty
                // forward snapshots in that case.
                if ($rebuilt === null) {
                    $anchorWeekEnding = Carbon::instance($weekAnchor)->endOfWeek(Carbon::SUNDAY)->startOfDay();
                    WeeklySnapshot::query()
                        ->where('user_id', $user->id)
                        ->where('week_ending', '>=', $anchorWeekEnding->toDateString())
                        ->delete();
                }
            }

            // PRs only ever lower, so a deleted run's record must be rebuilt from the
            // surviving runs rather than left pointing at a deleted activity.
            $personalRecords->rebuildForUser($user);

            // Polymorphic narration has no FK; purge the deleted run's rows (the
            // activity-keyed speech + insights, and the card-keyed flavor).
            Analysis::query()
                ->where('subject_type', Activity::class)
                ->where('subject_id', $localId)
                ->delete();

            if ($cardId !== null) {
                Analysis::query()
                    ->where('subject_type', RunCard::class)
                    ->where('subject_id', $cardId)
                    ->delete();
            }
        });

        Log::info('strava.webhook cleaned up deleted activity', [
            'user_id' => $user->id,
            'strava_external_id' => $this->stravaActivityId,
        ]);
    }
}
