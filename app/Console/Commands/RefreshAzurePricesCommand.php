<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('ai:refresh-azure-prices')]
#[Description('Refresh the Azure OpenAI per-1M token price map from the public Azure Retail Prices API')]
class RefreshAzurePricesCommand extends Command
{
    private const string RETAIL_PRICES_URL = 'https://prices.azure.com/api/retail/prices';

    public function handle(): int
    {
        /** @var array<string, array{input_per_1m: float, output_per_1m: float, currency: string}> $seed */
        $seed = (array) config('azure_openai.prices', []);
        $deployments = array_keys($seed);

        if ($deployments === []) {
            $this->info('No configured deployments to price; keeping config seed.');

            return self::SUCCESS;
        }

        try {
            $items = $this->fetchItems();
        } catch (Throwable $e) {
            Log::warning('azure_prices.refresh_failed', ['error' => $e->getMessage()]);
            $this->warn('Azure Retail Prices fetch failed; keeping config seed.');

            return self::SUCCESS;
        }

        $refreshed = $this->buildPriceMap($items, $deployments, $seed);

        Cache::forever((string) config('azure_openai.price_cache_key'), $refreshed);

        $this->info('Refreshed Azure prices for '.count($refreshed).' deployment(s).');

        return self::SUCCESS;
    }

    /**
     * Fetch the retail price items for the OpenAI service in USD.
     *
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
     * Match each configured deployment to its input/output retail meters and
     * derive a per-1M rate. Azure retail prices are quoted per 1K tokens, so
     * multiply by 1000. Deployments with no match retain the config seed rate.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $deployments
     * @param  array<string, array{input_per_1m: float, output_per_1m: float, currency: string}>  $seed
     * @return array<string, array{input_per_1m: float, output_per_1m: float, currency: string}>
     */
    private function buildPriceMap(array $items, array $deployments, array $seed): array
    {
        $map = [];

        foreach ($deployments as $deployment) {
            $input = $this->findRate($items, $deployment, isOutput: false);
            $output = $this->findRate($items, $deployment, isOutput: true);

            $map[$deployment] = [
                'input_per_1m' => $input ?? $seed[$deployment]['input_per_1m'],
                'output_per_1m' => $output ?? $seed[$deployment]['output_per_1m'],
                'currency' => 'USD',
            ];
        }

        return $map;
    }

    /**
     * Locate the per-1M token rate for a deployment's input or output meter. A
     * meter matches when its name mentions the deployment slug and the right
     * direction (Input vs Output); retail unitPrice is per-1K, scaled to 1M.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function findRate(array $items, string $deployment, bool $isOutput): ?float
    {
        // Whole-token match so the `gpt-4o` slug does not also match a
        // `gpt-4o-mini` meter (and bill the larger model at the mini rate).
        // Hyphens are normalised to spaces on both sides; the lookbehind/ahead
        // plus the explicit `mini` exclusion reject `gpt 4o mini ...` while still
        // matching versioned base meters like `gpt 4o 0806 ...`.
        $needle = '/(?<![a-z0-9])'.preg_quote(strtolower(str_replace('-', ' ', $deployment)), '/').'(?![a-z0-9])(?! mini)/';
        $direction = $isOutput ? 'output' : 'input';

        foreach ($items as $item) {
            $meterName = strtolower((string) ($item['meterName'] ?? ''));
            $haystack = strtolower(str_replace('-', ' ', $meterName));

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
