<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pure $ cost calculator over the manual price map (config azure_openai.prices,
 * keyed by model). A recorded deployment is resolved to its model via the
 * deployments map, then to a per-1M rate. Unpriced models cost 0.0.
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
        $rate = $this->priceFor($deployment);

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
     * The model a deployment is priced as: the config deployment->model map,
     * defaulting to the deployment name when it is not listed.
     */
    public function modelFor(string $deployment): string
    {
        // Index the map directly rather than via config() dot-notation, since
        // deployment/model names can contain dots (e.g. nuki-5.2, gpt-3.5-turbo).
        $deployments = (array) config('azure_openai.deployments', []);

        return (string) ($deployments[$deployment] ?? $deployment);
    }

    /**
     * The per-1M rate for a deployment (resolved through its model), or null when
     * the model has no configured rate.
     *
     * @return array{input_per_1m: float, output_per_1m: float, currency: string}|null
     */
    public function priceFor(string $deployment): ?array
    {
        $prices = (array) config('azure_openai.prices', []);
        $rate = $prices[$this->modelFor($deployment)] ?? null;

        if (! is_array($rate) || ! isset($rate['input_per_1m'], $rate['output_per_1m'])) {
            return null;
        }

        return [
            'input_per_1m' => (float) $rate['input_per_1m'],
            'output_per_1m' => (float) $rate['output_per_1m'],
            'currency' => 'USD',
        ];
    }
}
