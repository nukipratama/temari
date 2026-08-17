<?php

declare(strict_types=1);

namespace App\Services\Run\Trend;

use App\Models\ActivityDetail;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\Run\Metrics\StreamSummary;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Support\Carbon;

/**
 * Writes today's row into {@see TrendDailySnapshot}, once. Grow-forward only —
 * `firstOrCreate` is the load-bearing choice: re-running this for a day that
 * already has a row is a no-op, never an overwrite. There is deliberately no
 * backfill/rebuild path, unlike {@see \App\Services\Run\Metrics\WeeklyAggregator}'s
 * upsert-and-rebuild-forward behaviour — a day with no row simply has no
 * history yet.
 */
class TrendSnapshotWriter
{
    public function __construct(private readonly VdotEstimator $vdotEstimator)
    {
    }

    public function writeToday(User $user, ?Carbon $today = null): void
    {
        $today ??= Carbon::today();

        TrendDailySnapshot::query()->firstOrCreate(
            ['user_id' => $user->id, 'snapshot_date' => $today->toDateString()],
            [
                'vdot' => $this->vdotEstimator->estimate($user)['vdot'] ?? null,
                'pace_variability_sec' => $this->averagePaceVariabilitySec($user, $today),
            ],
        );
    }

    /**
     * Mean pace-variability across the user's runs that day, or null on a rest
     * day / when no run that day carried a usable stream. Null, never zero —
     * same "unscorable, not zero" convention
     * {@see \App\Services\Run\Metrics\TrainingLoad::loadDailyHistory()} already
     * uses for TRIMP.
     */
    private function averagePaceVariabilitySec(User $user, Carbon $today): ?float
    {
        $values = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $today->copy()->startOfDay())
            ->where('activity_details.start_date_local', '<=', $today->copy()->endOfDay())
            ->get(['activity_details.stream_summary'])
            ->pluck('stream_summary')
            ->map(static fn (?array $summary): ?float => StreamSummary::fromArray($summary)->paceVariabilitySec())
            ->filter(static fn (?float $value): bool => $value !== null);

        return $values->isEmpty() ? null : round((float) $values->avg(), 1);
    }
}
