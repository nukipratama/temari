<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Activity;
use App\Services\Strava\StravaCircuitBreaker;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * Superadmin control panel on /pulse: live ingest-backlog + circuit-breaker
 * state, plus the runtime kill-switches for AI and Strava. The toggle/reset
 * actions write to the durable app_config control plane.
 *
 * Not lazy: cheap count queries + control-plane reads, so deferring buys nothing.
 */
class SystemControl extends Card
{
    public function toggleAi(): void
    {
        $this->toggle(AppConfigKey::AiEnabled);
        // Bust the middleware's short-lived pause cache so the banner + hidden
        // re-analysis buttons reflect the flip on the next request, not up to a
        // minute later.
        Cache::forget(HandleInertiaRequests::AI_PAUSED_CACHE_KEY);
    }

    public function toggleStrava(): void
    {
        $this->toggle(AppConfigKey::StravaEnabled);
    }

    public function resetBreaker(): void
    {
        app(StravaCircuitBreaker::class)->reset();
    }

    public function render(): Renderable
    {
        $config = app(AppConfig::class);

        // One scan of `activities`: pending (retryable) vs stranded (gave up).
        $max = Activity::MAX_DETAIL_FETCH_ATTEMPTS;
        $backlog = DB::table('activities')
            ->whereNull('analyzed_at')
            ->selectRaw('SUM(detail_fail_count < ?) AS pending', [$max])
            ->selectRaw('SUM(detail_fail_count >= ?) AS stranded', [$max])
            ->first();

        $breaker = app(StravaCircuitBreaker::class)->snapshot();
        $stranded = (int) ($backlog->stranded ?? 0);

        return View::make('livewire.pulse.system-control', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'aiEnabled' => $config->boolean(AppConfigKey::AiEnabled),
            'stravaEnabled' => $config->boolean(AppConfigKey::StravaEnabled),
            'breaker' => $breaker,
            'pending' => (int) ($backlog->pending ?? 0),
            'stranded' => $stranded,
            'severity' => match (true) {
                $breaker['state'] === 'open' || $stranded > 0 => 'alert',
                $breaker['state'] === 'half_open' => 'warn',
                default => 'ok',
            },
        ]);
    }

    private function toggle(AppConfigKey $key): void
    {
        $config = app(AppConfig::class);
        $config->set($key, ! $config->boolean($key));
    }
}
