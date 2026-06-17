<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pure $ cost calculator. Resolves a recorded deployment to its model (config
 * azure_openai.deployments), then to a per-1M rate from the Azure Retail Prices
 * API. The retail map is fetched lazily and cached with a TTL; on a cold cache
 * the first cost computation (e.g. an /ai-usage view) refreshes it. When the
 * fetch is unavailable rates are absent and costs read 0.0. Unknown models cost 0.0.
 */
class LlmCostCalculator
{
    private const int PRICE_TTL_DAYS = 7;

    /**
     * Deployments already warned about this process, so an unknown deployment in
     * a hot loop logs once rather than per row.
     *
     * @var array<string, true>
     */
    private array $warnedDeployments = [];

    /**
     * Resolved retail-price map (model => rate), memoised per instance so a
     * per-row costing loop hits the cache/HTTP at most once.
     *
     * @var array<string, array{input_per_1m: float, output_per_1m: float, currency: string}>|null
     */
    private ?array $priceMap = null;

    private bool $usingRefreshed = false;

    public function __construct(private readonly AzureRetailPrices $retail)
    {
    }

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
     * True when rates came from the refreshed retail map (cache hit or a fresh
     * fetch). Drives the 'azure-retail' vs 'unavailable' price-source signal.
     */
    public function isUsingRefreshedPrices(): bool
    {
        $this->priceMap();

        return $this->usingRefreshed;
    }

    /**
     * Resolve the per-1M rate for a deployment: deployment -> model (config map,
     * defaulting to the deployment name) -> retail rate, or null when unpriced.
     *
     * @return array{input_per_1m: float, output_per_1m: float, currency: string}|null
     */
    private function rateFor(string $deployment): ?array
    {
        // Index the map directly rather than via config() dot-notation, since
        // deployment/model names can contain dots (e.g. nuki-5.2, gpt-3.5-turbo).
        $deployments = (array) config('azure_openai.deployments', []);
        $model = (string) ($deployments[$deployment] ?? $deployment);

        return $this->priceMap()[$model] ?? null;
    }

    /**
     * The model => rate map, memoised per instance.
     *
     * @return array<string, array{input_per_1m: float, output_per_1m: float, currency: string}>
     */
    private function priceMap(): array
    {
        return $this->priceMap ??= $this->resolvePriceMap();
    }

    /**
     * A cached refreshed map when present, otherwise a fresh retail fetch cached
     * for PRICE_TTL_DAYS. A failed/empty fetch returns [] (so rateFor finds no
     * rate) and is not cached, so the next computation retries.
     *
     * @return array<string, array{input_per_1m: float, output_per_1m: float, currency: string}>
     */
    private function resolvePriceMap(): array
    {
        $key = (string) config('azure_openai.price_cache_key');

        /** @var array<string, array{input_per_1m: float, output_per_1m: float, currency: string}>|null $cached */
        $cached = Cache::get($key);
        if (is_array($cached)) {
            $this->usingRefreshed = true;

            return $cached;
        }

        $fetched = $this->fetchPrices();
        $this->usingRefreshed = $fetched !== [];

        if ($fetched !== []) {
            Cache::put($key, $fetched, Carbon::now()->addDays(self::PRICE_TTL_DAYS));
        }

        return $fetched;
    }

    /**
     * @return array<string, array{input_per_1m: float, output_per_1m: float, currency: string}>
     */
    private function fetchPrices(): array
    {
        try {
            return $this->retail->fetch($this->billableModels());
        } catch (Throwable $e) {
            Log::warning('azure_prices.fetch_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * The set of models that can actually be billed: the primary deployment and
     * every per-narrator override, each resolved through the deployment->model
     * map (a deployment not in the map is assumed to already be a model name).
     * A model not in this set is never fetched, so it would silently cost 0.0.
     *
     * @return list<string>
     */
    private function billableModels(): array
    {
        /** @var array<string, string> $map */
        $map = (array) config('azure_openai.deployments', []);

        /** @var list<string> $deployments */
        $deployments = array_merge(
            [(string) config('azure_openai.deployment')],
            array_values((array) config('azure_openai.narrators', [])),
        );

        $models = array_map(
            static fn (string $deployment): string => (string) ($map[$deployment] ?? $deployment),
            $deployments,
        );

        return array_values(array_unique(array_filter($models, static fn (string $model): bool => $model !== '')));
    }
}
