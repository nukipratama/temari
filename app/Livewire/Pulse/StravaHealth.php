<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Livewire\Pulse\Concerns\SumsPulseTotals;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * Strava integration health, on the /pulse dashboard: the always-on complement
 * to the on-demand `strava:doctor` command. Live connection states + live
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
            ->where('detail_fail_count', '<', 5)
            ->count();

        $rateLimits = [
            '15 min' => $this->headroom('strava-api:15min', 200),
            'daily' => $this->headroom('strava-api:daily', 2000),
        ];

        [$trends, $time, $runAt] = $this->remember(fn (): array => [
            'webhook' => $this->asCount($this->aggregateTotal('strava_webhook', 'count')),
            'revoked' => $this->asCount($this->aggregateTotal('strava_revoked', 'count')),
            'rate_limited' => $this->asCount($this->aggregateTotal('strava_rate_limited', 'count')),
            // Activities ingested by the hourly poll / "Sync now" (sum of inserts).
            'synced' => $this->asCount($this->aggregateTotal('strava_sync', 'sum')),
        ]);

        return View::make('livewire.pulse.strava-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'time' => $time,
            'runAt' => $runAt,
            'connections' => [
                'active' => (int) ($connections->active ?? 0),
                'token_expired' => (int) ($connections->token_expired ?? 0),
                'revoked' => (int) ($connections->revoked ?? 0),
            ],
            'stranded' => $stranded,
            'rateLimits' => $rateLimits,
            'trends' => $trends,
            'webhookStatus' => $this->webhookStatus(),
        ]);
    }

    /**
     * @return array{remaining: int, max: int}
     */
    private function headroom(string $key, int $max): array
    {
        return ['remaining' => max(0, RateLimiter::remaining($key, $max)), 'max' => $max];
    }

    /**
     * @return array{configured: bool, subscription_id: string|null}
     */
    private function webhookStatus(): array
    {
        $subscriptionId = config('services.strava.webhook_subscription_id');

        return [
            'configured' => filled($subscriptionId),
            'subscription_id' => $subscriptionId,
        ];
    }
}
