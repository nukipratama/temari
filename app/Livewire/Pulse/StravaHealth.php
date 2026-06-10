<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use Illuminate\Database\Query\Builder;
use App\Livewire\Pulse\Concerns\SumsPulseTotals;
use App\Models\Activity;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * Strava integration health, on the /pulse dashboard: the always-on complement
 * to the on-demand `strava:doctor` command. Live connection states + per-user
 * rate-limit headroom + stranded-activity count, plus webhook / revocation /
 * rate-limit trends recorded across the Strava code.
 *
 * Not lazy: top-of-dashboard card with cheap queries, so deferring buys nothing.
 */
class StravaHealth extends Card
{
    use SumsPulseTotals;

    public function render(): Renderable
    {
        $now = now();

        $connections = DB::table('strava_connections')
            ->selectRaw('SUM(CASE WHEN revoked_at IS NOT NULL THEN 1 ELSE 0 END) AS revoked')
            ->selectRaw('SUM(CASE WHEN revoked_at IS NULL AND token_expires_at < ? THEN 1 ELSE 0 END) AS token_expired', [$now])
            ->selectRaw('SUM(CASE WHEN revoked_at IS NULL AND token_expires_at >= ? THEN 1 ELSE 0 END) AS active', [$now])
            ->first();

        $stranded = DB::table('activities')
            ->whereNull('analyzed_at')
            ->where('detail_fail_count', '<', Activity::MAX_DETAIL_FETCH_ATTEMPTS)
            ->count();

        [$trends, $time, $runAt] = $this->remember(fn (): array => [
            'webhook' => $this->asCount($this->aggregateTotal('strava_webhook', 'count')),
            'revoked' => $this->asCount($this->aggregateTotal('strava_revoked', 'count')),
            'rate_limited' => $this->asCount($this->aggregateTotal('strava_rate_limited', 'count')),
            // Activities ingested by the hourly poll / "Sync now" (sum of inserts).
            'synced' => $this->asCount($this->aggregateTotal('strava_sync', 'sum')),
        ]);

        $perUser = $this->perUserSyncHistory();
        $webhookStatus = $this->webhookStatus();

        $connectionStates = [
            'active' => (int) ($connections->active ?? 0),
            'token_expired' => (int) ($connections->token_expired ?? 0),
            'revoked' => (int) ($connections->revoked ?? 0),
        ];

        $anyUserFailed = collect($perUser)->contains(fn (array $row): bool => $row['is_failed']);

        return View::make('livewire.pulse.strava-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'time' => $time,
            'runAt' => $runAt,
            'connections' => $connectionStates,
            'stranded' => $stranded,
            'trends' => $trends,
            'perUser' => $perUser,
            'webhookStatus' => $webhookStatus,
            // Operational health only — a missing webhook is a config state with
            // its own indicator below, not a runtime failure, so it stays out of
            // the rollup (it would otherwise pin the badge to warn in every env
            // that doesn't run the webhook).
            'severity' => match (true) {
                $connectionStates['revoked'] > 0 || $anyUserFailed => 'alert',
                $connectionStates['token_expired'] > 0 || $stranded > 0 || $trends['rate_limited'] > 0 => 'warn',
                default => 'ok',
            },
        ]);
    }

    /**
     * @return list<array{user_id: int, user_name: string, last_sync: string|null, status: string, rate_limit_15min_remaining: int|null, rate_limit_daily_remaining: int|null, is_failed: bool}>
     */
    private function perUserSyncHistory(): array
    {
        $latestSyncs = DB::connection('analytics')
            ->table('strava_sync_logs')
            ->select('user_id', 'status', 'synced_at', 'rate_limit_15min_remaining', 'rate_limit_daily_remaining')
            ->whereIn(
                'id',
                fn (Builder $q): Builder => $q
                ->selectRaw('MAX(id)')
                ->from('strava_sync_logs')
                ->whereColumn('user_id', 'strava_sync_logs.user_id')
                ->groupBy('user_id')
            )
            ->get()
            ->keyBy('user_id');

        $activeUserIds = DB::table('strava_connections')
            ->whereNull('revoked_at')
            ->pluck('user_id');

        $userNames = DB::table('users')
            ->whereIn('id', $activeUserIds)
            ->pluck('name', 'id');

        $rows = [];
        foreach ($activeUserIds as $userId) {
            $sync = $latestSyncs->get($userId);

            $rows[] = [
                'user_id' => (int) $userId,
                'user_name' => (string) ($userNames[$userId] ?? "User {$userId}"),
                'last_sync' => $sync->synced_at ?? null,
                'status' => $sync->status ?? 'pending',
                '15min_remaining' => $sync?->rate_limit_15min_remaining,
                'daily_remaining' => $sync?->rate_limit_daily_remaining,
                'is_failed' => $sync !== null && \in_array($sync->status, ['error', 'rate_limited', 'token_expired', 'revoked'], true),
            ];
        }

        return $rows;
    }

    /**
     * Only whether the webhook is configured — the subscription id is an opaque
     * Strava identifier with no diagnostic value on the dashboard, so it's not
     * surfaced.
     *
     * @return array{configured: bool}
     */
    private function webhookStatus(): array
    {
        return [
            'configured' => filled(config('services.strava.webhook_subscription_id')),
        ];
    }
}
