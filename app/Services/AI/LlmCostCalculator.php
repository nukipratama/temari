<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pure $ cost calculator over the manual price map (config azure_openai.prices,
 * keyed by deployment name — the value recorded in ai_token_usages.model). An
 * unpriced deployment costs 0.0.
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
     *
     * `$cachedTokens` is the slice of `$promptTokens` the provider served from
     * its prompt cache. It only changes the bill when the deployment declares a
     * `cached_input_per_1m`; without one it bills as ordinary input, so a
     * deployment that has not been given a cached rate is unaffected.
     *
     * Reasoning tokens need no handling here: the provider already counts them
     * inside `$completionTokens`, so they bill at the output rate by arriving.
     */
    public function costFor(string $deployment, int $promptTokens, int $completionTokens, int $cachedTokens = 0): float
    {
        $rate = $this->priceFor($deployment);

        if ($rate === null) {
            if (! isset($this->warnedDeployments[$deployment])) {
                $this->warnedDeployments[$deployment] = true;
                Log::warning('llm_cost.unknown_deployment', ['deployment' => $deployment]);
            }

            return 0.0;
        }

        // Clamped because the two numbers arrive from the provider independently:
        // a cached count above the prompt count would otherwise bill negative.
        $cached = max(0, min($cachedTokens, $promptTokens));

        return (($promptTokens - $cached) / 1_000_000) * $rate['input_per_1m']
            + ($cached / 1_000_000) * ($rate['cached_input_per_1m'] ?? $rate['input_per_1m'])
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
            ->selectRaw('model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, SUM(cached_tokens) as cached')
            ->groupBy('model')
            ->get();

        $total = 0.0;
        foreach ($rows as $row) {
            $total += $this->costFor((string) $row->model, (int) $row->prompt, (int) $row->completion, (int) $row->cached);
        }

        return $total;
    }

    /**
     * The per-1M rates for a deployment, or null when it has no configured rate.
     *
     * `cached_input_per_1m` is optional: null means the deployment has no
     * declared cache discount and cached input bills as ordinary input.
     *
     * @return array{input_per_1m: float, output_per_1m: float, cached_input_per_1m: float|null}|null
     */
    public function priceFor(string $deployment): ?array
    {
        // Index the map directly rather than via config() dot-notation, since
        // deployment names can contain dots (e.g. nuki-5.2).
        $prices = (array) config('azure_openai.prices', []);
        $rate = $prices[$deployment] ?? null;

        if (! is_array($rate) || ! isset($rate['input_per_1m'], $rate['output_per_1m'])) {
            return null;
        }

        return [
            'input_per_1m' => (float) $rate['input_per_1m'],
            'output_per_1m' => (float) $rate['output_per_1m'],
            'cached_input_per_1m' => isset($rate['cached_input_per_1m']) ? (float) $rate['cached_input_per_1m'] : null,
        ];
    }
}
