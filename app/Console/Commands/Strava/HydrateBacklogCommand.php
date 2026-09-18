<?php

declare(strict_types=1);

namespace App\Console\Commands\Strava;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\Scopes\AnalyzedScope;
use App\Models\User;
use App\Services\Run\Ingest\DetailHydrator;
use App\Services\Strava\StravaClient;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Signature('strava:hydrate-backlog {--batch= : Runs to hydrate this tick; defaults to whatever the background read headroom affords}')]
#[Description('Hydrate summary-only runs oldest-first, paced by the background share of the Strava read budget and split evenly across users.')]
class HydrateBacklogCommand extends Command
{
    /** Detail + streams, the two calls ActivityPipeline makes per run. */
    private const int READS_PER_RUN = 2;

    public function handle(AppConfig $config, StravaClient $client, DetailHydrator $hydrator): int
    {
        if (! $config->boolean(AppConfigKey::StravaEnabled)) {
            $this->warn('Strava is disabled (kill-switch); skipping hydration drain.');

            return self::SUCCESS;
        }

        $budget = $this->budget($client, $this->option('batch'));

        if ($budget < 1) {
            $this->line('Background read headroom is spent; nothing hydrated this tick.');

            return self::SUCCESS;
        }

        $userIds = $this->usersWithBacklog();

        if ($userIds->isEmpty()) {
            $this->line('No summary-only runs left to hydrate.');

            return self::SUCCESS;
        }

        $perUser = max(1, intdiv($budget, $userIds->count()));
        $dispatched = 0;

        foreach ($userIds as $userId) {
            $remaining = $budget - $dispatched;

            if ($remaining < 1) {
                break;
            }

            $dispatched += $this->hydrateFor($hydrator, $userId, min($perUser, $remaining));
        }

        $this->line("Queued {$dispatched} run(s) for hydration across {$userIds->count()} user(s).");

        return self::SUCCESS;
    }

    /**
     * Runs affordable this tick. An explicit `$override` (the command's
     * `--batch`) skips the calculation for a manual catch-up; otherwise the
     * tighter of the two background buckets decides, so the drain shrinks
     * itself as live ingest spends the shared pool. Public so the immediate
     * post-connect hydration ({@see \App\Jobs\Strava\HydrateBacklogForUserJob})
     * paces itself against the same headroom, without an override.
     */
    public function budget(StravaClient $client, int|string|null $override = null): int
    {
        if ($override !== null) {
            return max(1, (int) $override);
        }

        return intdiv(min($client->backgroundHeadroom()), self::READS_PER_RUN);
    }

    /**
     * Non-demo users with a live connection and at least one hydratable run.
     *
     * @return Collection<int, int>
     */
    private function usersWithBacklog(): Collection
    {
        return User::query()
            ->where('is_demo', false)
            ->whereHas('stravaConnection', fn ($query) => $query->whereNull('revoked_at'))
            ->whereHas('activities', $this->hydratable(...))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
    }

    /**
     * Oldest-first: each run lands with its complete past already hydrated, so
     * card PR flags, moods and Past You comparisons are right the moment it
     * lands instead of needing a later replay. See
     * {@see \App\Actions\Run\Story\RecomputeCardClaimsAction} for the safety
     * net this leaves in place for runs that still arrive out of order (a
     * live run synced mid-drain, a backdated upload). Ordered by a correlated
     * subquery rather than a join so {@see AnalyzedScope} and the
     * `summaryOnly` scope keep their own qualified columns. Public for the
     * same reason as {@see self::budget()}.
     */
    public function hydrateFor(DetailHydrator $hydrator, int $userId, int $take): int
    {
        return Activity::query()
            ->where('user_id', $userId)
            ->tap($this->hydratable(...))
            ->orderBy(
                ActivityDetail::query()
                    ->select('start_date_local')
                    ->whereColumn('activity_details.activity_id', 'activities.id')
            )
            ->limit($take)
            ->pluck('id')
            ->filter(fn (mixed $id): bool => $hydrator->hydrate((int) $id))
            ->count();
    }

    /**
     * Rows the pipeline can still make progress on. A permanent 4xx leaves
     * `ingest_state` at `summary` on purpose, so the attempt count — not the
     * state — is what separates "not fetched yet" from "never will be".
     * Stubs (`analyzed_at` null) stay hidden by {@see AnalyzedScope}: those are
     * `strava:ingest`'s to drain, and dispatching both at one row would spend
     * its two reads twice.
     *
     * @param  Builder<Activity>  $query
     */
    private function hydratable(Builder $query): void
    {
        $query
            ->summaryOnly()
            ->where($query->qualifyColumn('detail_fail_count'), '<', Activity::MAX_DETAIL_FETCH_ATTEMPTS);
    }
}
