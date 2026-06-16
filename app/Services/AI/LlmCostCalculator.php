<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pure $ cost calculator over the Azure deployment price map. Prefers the
 * refreshed retail-price map from the cache (written weekly by
 * ai:refresh-azure-prices) and falls back to the config seed when the cache is
 * cold. Unknown deployments cost 0.0 and warn once per process.
 */
class LlmCostCalculator
{
    /**
     * Deployments already warned about this process, so an unknown deployment in
     * a hot loop logs once rather than per row.
     *
     * @var array<string, true>
     */
    private array $warnedDeployments = [];

    /**
     * Cost in USD for a single call's token split against the deployment's rate.
     */
    public function costFor(string $deployment, int $promptTokens, int $completionTokens): float
    {
        $rate = $this->rateFor($deployment);

        if ($rate === null) {
            if (! isset($this->warnedDeployments[$deployment])) {
                $this->warnedDeployments[$deployment] = true;
                Log::warning('llm_cost.unknown_deployment', ['deployment' => $deployment]);
            }

            return 0.0;
        }

        return ($promptTokens / 1_000_000) * $rate['input_per_1m']
            + ($completionTokens / 1_000_000) * $rate['output_per_1m'];
    }

    /**
     * Total USD cost of today's ai_token_usages rows (analytics connection),
     * grouped by deployment so each group bills against its own rate.
     */
    public function dailyCost(): float
    {
        $rows = DB::connection('analytics')->table('ai_token_usages')
            ->whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])
            ->selectRaw('model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion')
            ->groupBy('model')
            ->get();

        $total = 0.0;
        foreach ($rows as $row) {
            $total += $this->costFor((string) $row->model, (int) $row->prompt, (int) $row->completion);
        }

        return $total;
    }

    /**
     * True when the refreshed retail-price cache is populated (drives the
     * 'azure-retail' vs 'config-fallback' price-source signal).
     */
    public function isUsingRefreshedPrices(): bool
    {
        return Cache::has((string) config('azure_openai.price_cache_key'));
    }

    /**
     * Resolve the per-1M rate for a deployment, preferring the cached refreshed
     * map over the config seed.
     *
     * @return array{input_per_1m: float, output_per_1m: float, currency: string}|null
     */
    private function rateFor(string $deployment): ?array
    {
        /** @var array<string, array{input_per_1m: float, output_per_1m: float, currency: string}> $refreshed */
        $refreshed = Cache::get((string) config('azure_openai.price_cache_key'), []);

        $rate = $refreshed[$deployment] ?? config("azure_openai.prices.{$deployment}");

        if (! is_array($rate) || ! isset($rate['input_per_1m'], $rate['output_per_1m'])) {
            return null;
        }

        return [
            'input_per_1m' => (float) $rate['input_per_1m'],
            'output_per_1m' => (float) $rate['output_per_1m'],
            'currency' => (string) ($rate['currency'] ?? 'USD'),
        ];
    }
}
