<?php

declare(strict_types=1);

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

/**
 * Fetches per-1M token rates from the public Azure Retail Prices API, keyed by
 * model name (e.g. gpt-4o). Deployment names are arbitrary, so callers map their
 * deployment to a model first (config azure_openai.deployments) before pricing.
 */
class AzureRetailPrices
{
    private const string RETAIL_PRICES_URL = 'https://prices.azure.com/api/retail/prices';

    /**
     * Per-1M input/output rates for the given models. Models with no matching
     * retail meter are omitted. Throws on transport/HTTP failure so the caller
     * can decide how to degrade.
     *
     * @param  list<string>  $models
     * @return array<string, array{input_per_1m: float, output_per_1m: float, currency: string}>
     */
    public function fetch(array $models): array
    {
        if ($models === []) {
            return [];
        }

        $items = $this->fetchItems();
        $map = [];

        foreach ($models as $model) {
            $input = $this->findRate($items, $model, isOutput: false);
            $output = $this->findRate($items, $model, isOutput: true);

            if ($input === null || $output === null) {
                continue;
            }

            $map[$model] = [
                'input_per_1m' => $input,
                'output_per_1m' => $output,
                'currency' => 'USD',
            ];
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchItems(): array
    {
        $response = Http::acceptJson()
            ->get(self::RETAIL_PRICES_URL, [
                'currencyCode' => 'USD',
                '$filter' => "serviceName eq 'Cognitive Services' and priceType eq 'Consumption'",
            ])
            ->throw();

        /** @var array{Items?: list<array<string, mixed>>} $body */
        $body = $response->json();

        return $body['Items'] ?? [];
    }

    /**
     * Locate the per-1M rate for a model's input or output meter. A meter matches
     * on whole-token model name + direction; retail unitPrice is per-1K, scaled
     * to 1M. The `(?! mini)` guard stops gpt-4o matching a gpt-4o-mini meter.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function findRate(array $items, string $model, bool $isOutput): ?float
    {
        $needle = '/(?<![a-z0-9])'.preg_quote(strtolower(str_replace('-', ' ', $model)), '/').'(?![a-z0-9])(?! mini)/';
        $direction = $isOutput ? 'output' : 'input';

        foreach ($items as $item) {
            $haystack = strtolower(str_replace('-', ' ', (string) ($item['meterName'] ?? '')));

            if (preg_match($needle, $haystack) !== 1) {
                continue;
            }
            if (! str_contains($haystack, $direction)) {
                continue;
            }

            return (float) ($item['unitPrice'] ?? 0) * 1000;
        }

        return null;
    }
}
