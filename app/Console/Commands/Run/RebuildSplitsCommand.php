<?php

declare(strict_types=1);

namespace App\Console\Commands\Run;

use App\Models\Activity;
use App\Models\User;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

/**
 * Two passes, in order: recompute every `stream_summary` off stored streams,
 * then reset and replay personal records and weekly snapshots once per user.
 *
 * Idempotent: both passes derive everything from stored data and can be re-run freely.
 */
#[Signature('run:rebuild-splits {--user= : Limit to one user id}')]
#[Description('Recompute per-km splits, personal records and weekly snapshots from stored data under the current rules.')]
class RebuildSplitsCommand extends Command
{
    public function handle(
        ActivityPipeline $pipeline,
        PersonalRecords $personalRecords,
        WeeklyAggregator $weeklyAggregator,
    ): int {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Recovery);

        $this->recomputeSummaries($pipeline);
        $this->rebuildRecordsAndSnapshots($personalRecords, $weeklyAggregator);

        return self::SUCCESS;
    }

    /**
     * Pass 1: zero HTTP. Aggregates are deliberately not rebuilt here —
     * `recomputeSummary()` would otherwise roll every week forward once per
     * activity, which is quadratic over a full history. Pass 2 does it once.
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

        $this->line("Pass 1: recomputed <info>{$recomputed}</info> stream summary(ies).");
    }

    /**
     * Pass 2: reset, don't re-detect. `updateIfFaster()` only ever writes a
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

        $this->line("Pass 2: rebuilt records and weekly snapshots for <info>{$users}</info> user(s).");
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
