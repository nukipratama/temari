<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Services\AI\LlmCostCalculator;
use App\Services\Strava\StravaClient;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * The two budgets that stop the app when they run out, as bars: today's LLM
 * spend against the app-wide ceiling, and the shared Strava read allocation
 * against its 15-minute and daily buckets. Both are per-client / app-wide, so
 * one bar each is the whole picture.
 *
 * Not lazy: one grouped scan of today's usage rows plus two rate-limiter reads,
 * so deferring buys nothing.
 */
class BurnDown extends Card
{
    private const int STRAVA_15MIN_MAX = 200;

    private const int STRAVA_DAILY_MAX = 2000;

    public function render(LlmCostCalculator $cost, StravaClient $strava): Renderable
    {
        $totalCeiling = config('azure_openai.daily_cost_ceiling_total');
        $totalCeiling = $totalCeiling === null ? null : (float) $totalCeiling;
        $spent = $cost->dailyCost();

        $remaining = $strava->rateLimitRemaining();

        $bars = [
            $this->bar('LLM spend today', $spent, $totalCeiling, '$'),
            $this->bar('Strava reads, 15 min', self::STRAVA_15MIN_MAX - $remaining['15min'], (float) self::STRAVA_15MIN_MAX, ''),
            $this->bar('Strava reads, today', self::STRAVA_DAILY_MAX - $remaining['daily'], (float) self::STRAVA_DAILY_MAX, ''),
        ];

        return View::make('livewire.pulse.burn-down', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'bars' => $bars,
            'severity' => match (true) {
                collect($bars)->contains(fn (array $bar): bool => $bar['pct'] !== null && $bar['pct'] >= 100) => 'alert',
                collect($bars)->contains(fn (array $bar): bool => $bar['pct'] !== null && $bar['pct'] >= 80) => 'warn',
                default => 'ok',
            },
        ]);
    }

    /**
     * @return array{label: string, used: string, ceiling: string|null, pct: float|null, tone: string}
     */
    private function bar(string $label, float $used, ?float $ceiling, string $prefix): array
    {
        $pct = $ceiling === null || $ceiling <= 0.0 ? null : round(($used / $ceiling) * 100, 1);

        return [
            'label' => $label,
            'used' => $prefix.($prefix === '$' ? number_format($used, 2) : number_format($used)),
            'ceiling' => $ceiling === null ? null : $prefix.($prefix === '$' ? number_format($ceiling, 2) : number_format($ceiling)),
            'pct' => $pct,
            'tone' => match (true) {
                $pct === null => 'neutral',
                $pct >= 100 => 'alert',
                $pct >= 80 => 'warn',
                default => 'neutral',
            },
        ];
    }
}
