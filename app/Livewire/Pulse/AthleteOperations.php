<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Models\AI\Analysis;
use App\Models\Analytics\StravaSyncLog;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\AI\AnalysisSubjectMap;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * One line per athlete on the /pulse dashboard: when Strava last synced for
 * them and how it arrived, and when each notification channel last settled for
 * them. The Strava and delivery cards above answer both questions app-wide;
 * this is the only place that answers them per person, which is what triage
 * ("is it everyone or is it him?") actually asks.
 *
 * Not lazy: bounded scans over small tables, so deferring buys nothing.
 */
class AthleteOperations extends Card
{
    private const int LISTED_ATHLETES = 25;

    private const int SCANNED_DELIVERIES = 200;

    public function render(): Renderable
    {
        $athletes = User::query()
            ->orderBy('is_demo')
            ->orderBy('name')
            ->limit(self::LISTED_ATHLETES)
            ->get(['id', 'name', 'is_demo']);

        $syncs = $this->latestSyncs();
        $deliveries = $this->latestDeliveries();

        $rows = $athletes->map(function (User $athlete) use ($syncs, $deliveries): array {
            $sync = $syncs->get($athlete->id);

            return [
                'name' => $athlete->name,
                'isDemo' => $athlete->is_demo,
                'syncedAt' => $sync?->synced_at,
                'syncStatus' => $sync->status ?? 'never',
                'syncPath' => $this->syncPath($sync),
                'channels' => $deliveries[$athlete->id] ?? [],
            ];
        });

        $failing = $rows->contains(
            fn (array $row): bool => in_array($row['syncStatus'], ['error', 'revoked'], true)
                || collect($row['channels'])->contains('status', 'failed'),
        );

        return View::make('livewire.pulse.athlete-operations', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'athletes' => $rows,
            'severity' => $failing ? 'alert' : 'ok',
        ]);
    }

    /**
     * @return EloquentCollection<int, StravaSyncLog>
     */
    private function latestSyncs(): EloquentCollection
    {
        return StravaSyncLog::query()
            ->select('user_id', 'status', 'synced_at', 'api_calls_used')
            ->whereIn(
                'id',
                fn (Builder $query): Builder => $query
                    ->selectRaw('MAX(id)')
                    ->from('strava_sync_logs')
                    ->groupBy('user_id'),
            )
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Which path delivered the last sync. Inferred, not recorded: the webhook
     * push ingests one known activity and spends no list call, while the poll
     * and a manual sync always spend at least one. An errored sync spends none
     * either, so it stays unattributed.
     */
    private function syncPath(?StravaSyncLog $sync): ?string
    {
        if ($sync === null || $sync->status !== 'success') {
            return null;
        }

        return $sync->api_calls_used === 0 ? 'webhook' : 'poll';
    }

    /**
     * Newest settled delivery per (athlete, channel), from a bounded scan of the
     * delivery table. Deliveries hang off an analysis, whose owner comes from
     * the subject map — analyses carry no user_id of their own.
     *
     * @return array<int, list<array{channel: string, status: string, at: \Illuminate\Support\Carbon|null}>>
     */
    private function latestDeliveries(): array
    {
        $deliveries = NotificationDelivery::query()
            ->orderByDesc('id')
            ->limit(self::SCANNED_DELIVERIES)
            ->get(['id', 'analysis_id', 'channel', 'status', 'created_at', 'settled_at']);

        if ($deliveries->isEmpty()) {
            return [];
        }

        $analyses = Analysis::query()
            ->whereIn('id', $deliveries->pluck('analysis_id')->unique()->all())
            ->get(['id', 'subject_type', 'subject_id']);

        $owners = AnalysisSubjectMap::ownerIdsForRows($analyses);

        $byAthlete = [];
        $seen = [];
        foreach ($deliveries as $delivery) {
            $userId = $owners[$delivery->analysis_id] ?? null;
            $key = $userId.':'.$delivery->channel;

            if ($userId === null || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $byAthlete[$userId][] = [
                'channel' => $delivery->channel,
                'status' => $delivery->status->value,
                'at' => $delivery->settled_at ?? $delivery->created_at,
            ];
        }

        return $byAthlete;
    }
}
