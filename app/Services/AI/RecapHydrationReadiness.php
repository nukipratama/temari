<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Holds a weekly recap back while the week it narrates is still filling in.
 *
 * A recap is requested `invalidate: false`, so a week narrated against half its
 * splits and a null `weekly_trimp` keeps that thin story permanently. The
 * `strava:hydrate-backlog` drain and the Monday kickoff have no ordering
 * between them, so this is the ordering: a week whose activities the pipeline
 * still owes a hydration is not narrated yet.
 *
 * The hold is bounded by wall clock rather than a deferral counter, so it needs
 * no state of its own — a run that will never hydrate (detail attempts spent)
 * already drops out of {@see Activity::awaitingHydration()}, and everything
 * else is capped by the grace window below.
 *
 * @see docs/decisions/recap-waits-for-hydration.md
 */
class RecapHydrationReadiness
{
    /**
     * The subset of $snapshots whose week may be narrated now. Weeks still
     * awaiting hydration inside the grace window are dropped; past it they are
     * kept and narrate what exists.
     *
     * @param  Collection<int, WeeklySnapshot>  $snapshots
     * @return Collection<int, WeeklySnapshot>
     */
    public function ready(Collection $snapshots): Collection
    {
        if ($snapshots->isEmpty()) {
            return $snapshots;
        }

        /** @var list<int> $userIds */
        $userIds = $snapshots->pluck('user_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        $awaiting = $this->weeksAwaitingHydration($userIds);
        $connectedAt = $this->connectedAt($userIds);
        $now = Carbon::now();

        /** @var Collection<int, WeeklySnapshot> $ready */
        $ready = new Collection();
        /** @var Collection<int, WeeklySnapshot> $deferred */
        $deferred = new Collection();
        /** @var Collection<int, WeeklySnapshot> $forced */
        $forced = new Collection();

        foreach ($snapshots as $snapshot) {
            $userId = (int) $snapshot->user_id;
            $weekEnding = $snapshot->week_ending->toDateString();

            if (! isset($awaiting[$this->key($userId, $weekEnding)])) {
                $ready->push($snapshot);

                continue;
            }

            if ($now->lt($this->graceEndsAt($weekEnding, $connectedAt[$userId] ?? null))) {
                $deferred->push($snapshot);

                continue;
            }

            $forced->push($snapshot);
            $ready->push($snapshot);
        }

        $this->log('narrator.recap.hydration_deferred', $deferred);
        $this->log('narrator.recap.hydration_grace_expired', $forced);

        return $ready;
    }

    /**
     * When a week stops waiting and narrates whatever has landed. Anchored at
     * the later of the week's own close and the athlete's Strava connection:
     * a first connect backfills weeks that closed long ago, and those need the
     * same grace as a week that closed last night.
     */
    private function graceEndsAt(string $weekEnding, ?Carbon $connectedAt): Carbon
    {
        $anchor = Carbon::parse($weekEnding)->endOfDay();

        if ($connectedAt !== null && $connectedAt->gt($anchor)) {
            $anchor = $connectedAt->copy();
        }

        return $anchor->addHours((int) config('ai.recap_hydration_grace_hours', 48));
    }

    /**
     * `"{user}|{week_ending}"` for every week holding a run the pipeline can
     * still hydrate. A webhook stub carries no `activity_details` row and so no
     * date to place it in a week; `strava:ingest` gives it one before it can
     * count against any recap.
     *
     * @param  list<int>  $userIds
     * @return array<string, true>
     */
    private function weeksAwaitingHydration(array $userIds): array
    {
        return Activity::query()
            ->awaitingHydration()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->whereIn('activities.user_id', $userIds)
            ->get(['activities.user_id', 'activity_details.start_date_local'])
            ->mapWithKeys(fn (Activity $activity): array => [
                $this->key(
                    (int) $activity->user_id,
                    Carbon::parse((string) $activity->getAttribute('start_date_local'))
                        ->endOfWeek(Carbon::SUNDAY)
                        ->toDateString(),
                ) => true,
            ])
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, Carbon>
     */
    private function connectedAt(array $userIds): array
    {
        return StravaConnection::query()
            ->whereIn('user_id', $userIds)
            ->pluck('created_at', 'user_id')
            ->map(fn (mixed $at): Carbon => Carbon::parse($at))
            ->all();
    }

    private function key(int $userId, string $weekEnding): string
    {
        return $userId.'|'.$weekEnding;
    }

    /**
     * @param  Collection<int, WeeklySnapshot>  $snapshots
     */
    private function log(string $event, Collection $snapshots): void
    {
        if ($snapshots->isEmpty()) {
            return;
        }

        Log::info($event, [
            'count' => $snapshots->count(),
            'weeks' => $snapshots
                ->map(fn (WeeklySnapshot $snapshot): string => $snapshot->user_id.'|'.$snapshot->week_ending->toDateString())
                ->all(),
        ]);
    }
}
