<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Strava\Exceptions\StravaCircuitOpenException;
use App\Services\Strava\Exceptions\StravaConnectionRevokedException;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use App\Services\Strava\Exceptions\StravaTokenRefreshFailedException;
use App\Services\Strava\StravaClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Three passes, in order: fetch the missing `laps` blobs from Strava, recompute
 * every `stream_summary` off stored streams, then reset and replay personal
 * records and weekly snapshots once per user.
 *
 * Idempotent and resumable: pass 1 is scoped to rows that still have no laps,
 * passes 2 and 3 derive everything from stored data and can be re-run freely.
 */
#[Signature('run:rebuild-splits {--user= : Limit to one user id} {--skip-fetch : Skip the Strava fetch; recompute and rebuild from stored data only} {--sleep=5 : Seconds to pause between Strava calls}')]
#[Description('Backfill Strava laps, then recompute per-km splits, personal records and weekly snapshots under the current rules.')]
class RebuildSplitsCommand extends Command
{
    public function handle(
        StravaClient $client,
        ActivityPipeline $pipeline,
        PersonalRecords $personalRecords,
        WeeklyAggregator $weeklyAggregator,
    ): int {
        if ($this->option('skip-fetch')) {
            $this->line('Pass 1 skipped (--skip-fetch): no Strava calls.');
        } else {
            $this->fetchLaps($client);
        }

        $this->recomputeSummaries($pipeline);
        $this->rebuildRecordsAndSnapshots($personalRecords, $weeklyAggregator);

        return self::SUCCESS;
    }

    /**
     * Pass 1: one `GET /activities/{id}` per activity that has no stored laps.
     * A rate-limit ends the pass rather than the command — what was fetched
     * stays fetched, and the next run picks up where this one stopped.
     */
    private function fetchLaps(StravaClient $client): void
    {
        $sleep = (float) $this->option('sleep');
        $fetched = 0;
        $skipped = 0;

        $activities = $this->scopedActivities()
            ->whereHas('detail', fn (Builder $query): Builder => $query->whereNull('laps'))
            ->with(['detail', 'user.stravaConnection'])
            ->lazyById();

        foreach ($activities as $activity) {
            $detail = $activity->detail;
            $connection = $activity->user->stravaConnection;
            if ($detail === null || $connection === null) {
                $skipped++;

                continue;
            }

            try {
                $this->storeLaps($client, $activity, $detail, $connection);
                $fetched++;
            } catch (StravaRateLimitedException|StravaCircuitOpenException|StravaConnectionRevokedException|StravaTokenRefreshFailedException $e) {
                // Every remaining call would hit the same wall, so stop instead
                // of spending the rest of the shared budget proving it.
                $this->warn("Pass 1 stopped early: {$e->getMessage()}");
                break;
            } catch (Throwable $e) {
                // One unreachable activity (deleted, unshared, transport blip)
                // must not strand the rest: it keeps its null laps and is picked
                // up by the next run.
                $this->warn("Activity {$activity->id}: {$e->getMessage()}");
                $skipped++;
            }

            if ($sleep > 0) {
                Sleep::for($sleep)->seconds();
            }
        }

        $this->line("Pass 1: fetched laps for <info>{$fetched}</info> activity(ies), skipped <info>{$skipped}</info>.");
    }

    /**
     * Stores `[]` rather than null when Strava reports no laps, so a run without
     * them leaves the resume scope instead of being re-fetched on every pass.
     * `ActivityDetail::laps()` already collapses both to an empty array.
     */
    private function storeLaps(
        StravaClient $client,
        Activity $activity,
        ActivityDetail $detail,
        StravaConnection $connection,
    ): void {
        $response = $client
            ->get($connection, "/activities/{$activity->strava_external_id}")
            ->json();

        $laps = is_array($response) ? $response['laps'] ?? null : null;

        $detail->update(['laps' => is_array($laps) ? $laps : []]);
    }

    /**
     * Pass 2: zero HTTP. Aggregates are deliberately not rebuilt here —
     * `recomputeSummary()` would otherwise roll every week forward once per
     * activity, which is quadratic over a full history. Pass 3 does it once.
     */
    private function recomputeSummaries(ActivityPipeline $pipeline): void
    {
        $recomputed = 0;

        $activities = $this->scopedActivities()
            ->whereHas('stream')
            ->with(['detail', 'stream', 'user'])
            ->lazyById();

        foreach ($activities as $activity) {
            $pipeline->recomputeSummary($activity, rebuildAggregates: false);
            $recomputed++;
        }

        $this->line("Pass 2: recomputed <info>{$recomputed}</info> stream summary(ies).");
    }

    /**
     * Pass 3: reset, don't re-detect. `updateIfFaster()` only ever writes a
     * faster time and every recomputed split is slower or equal, so re-detecting
     * over the old rows would leave stale crowns standing. `rebuildForUser()`
     * drops the user's records first and replays oldest-first.
     */
    private function rebuildRecordsAndSnapshots(
        PersonalRecords $personalRecords,
        WeeklyAggregator $weeklyAggregator,
    ): void {
        $users = 0;

        foreach ($this->scopedUsers() as $user) {
            $personalRecords->rebuildForUser($user);
            $weeklyAggregator->rebuildFor($user);
            $users++;
        }

        $this->line("Pass 3: rebuilt records and weekly snapshots for <info>{$users}</info> user(s).");
    }

    /**
     * @return Builder<Activity>
     */
    private function scopedActivities(): Builder
    {
        $userId = $this->option('user');

        return Activity::query()
            ->when($userId !== null, fn (Builder $query): Builder => $query->where('user_id', (int) $userId));
    }

    /**
     * @return Collection<int, User>
     */
    private function scopedUsers(): Collection
    {
        $userId = $this->option('user');

        return User::query()
            ->when($userId !== null, fn (Builder $query): Builder => $query->where('id', (int) $userId))
            ->whereHas('activities')
            ->orderBy('id')
            ->get();
    }
}
