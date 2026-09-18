<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Activity;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Holds a weekly or monthly recap back while the period it narrates is still
 * filling in.
 *
 * A recap is requested `invalidate: false`, so a week narrated against half its
 * splits and a null `weekly_trimp`, or a month narrated before its PRs and load
 * land, keeps that thin story permanently. The
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
    public function __construct(private readonly HydrationBacklog $backlog)
    {
    }

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
        $connectedAt = $this->backlog->connectedAtFor($userIds);
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

            if ($now->lt($this->graceEndsAt(Carbon::parse($weekEnding)->endOfDay(), $connectedAt[$userId] ?? null))) {
                $deferred->push($snapshot);

                continue;
            }

            $forced->push($snapshot);
            $ready->push($snapshot);
        }

        $weekKey = fn (WeeklySnapshot $snapshot): string => $this->key((int) $snapshot->user_id, $snapshot->week_ending->toDateString());
        $this->log('narrator.recap.hydration_deferred', 'weeks', $deferred->map($weekKey));
        $this->log('narrator.recap.hydration_grace_expired', 'weeks', $forced->map($weekKey));

        return $ready;
    }

    /**
     * The subset of one athlete's $months (`Y-m`) that may be narrated now, by
     * the same rule {@see ready()} applies to a week.
     *
     * @param  Collection<int, string>  $months
     * @return Collection<int, string>
     */
    public function readyMonths(int $userId, Collection $months): Collection
    {
        if ($months->isEmpty()) {
            return $months;
        }

        $awaiting = $this->backlog->awaitingHydration([$userId])
            ->selectRaw("DISTINCT DATE_FORMAT(activity_details.start_date_local, '%Y-%m') as month")
            ->pluck('month')
            ->flip();
        $connectedAt = $this->backlog->connectedAt($userId);
        $now = Carbon::now();

        $held = $months->filter(fn (string $month): bool => $awaiting->has($month));
        $deferred = $held->filter(fn (string $month): bool => $now->lt(
            $this->graceEndsAt(Carbon::parse($month.'-01')->endOfMonth(), $connectedAt),
        ));

        $monthKey = fn (string $month): string => $this->key($userId, $month);
        $this->log('narrator.recap.hydration_deferred', 'months', $deferred->map($monthKey));
        $this->log('narrator.recap.hydration_grace_expired', 'months', $held->diff($deferred)->map($monthKey));

        return $months->diff($deferred)->values();
    }

    /**
     * When a period stops waiting and narrates whatever has landed. Anchored at
     * the later of the period's own close and the athlete's Strava connection:
     * a first connect backfills periods that closed long ago, and those need the
     * same grace as one that closed last night.
     */
    private function graceEndsAt(Carbon $closedAt, ?Carbon $connectedAt): Carbon
    {
        $anchor = $closedAt->copy();

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
        return $this->backlog->awaitingHydration($userIds)
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

    private function key(int $userId, string $period): string
    {
        return $userId.'|'.$period;
    }

    /**
     * @param  Collection<int, string>  $keys
     */
    private function log(string $event, string $unit, Collection $keys): void
    {
        if ($keys->isEmpty()) {
            return;
        }

        Log::info($event, [
            'count' => $keys->count(),
            $unit => $keys->values()->all(),
        ]);
    }
}
