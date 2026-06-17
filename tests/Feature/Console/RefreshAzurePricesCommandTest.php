<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('azure_openai.prices', [
        'gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00, 'currency' => 'USD'],
        'gpt-4o-mini' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60, 'currency' => 'USD'],
    ]);
    config()->set('azure_openai.price_cache_key', 'azure_openai.prices.refreshed');
    Cache::forget('azure_openai.prices.refreshed');
});

/**
 * @param  list<array{meterName:string, unitPrice:float}>  $meters
 */
function fakeRetailResponse(array $meters): array
{
    return ['Items' => array_map(
        fn (array $m): array => ['meterName' => $m['meterName'], 'unitPrice' => $m['unitPrice'], 'currencyCode' => 'USD'],
        $meters,
    )];
}

it('refreshes prices from the retail API into the cache (per-1K scaled to per-1M)', function (): void {
    Http::fake([
        'prices.azure.com/*' => Http::response(fakeRetailResponse([
            // Retail prices are per-1K tokens; the command multiplies by 1000.
            ['meterName' => 'gpt-4o Input Tokens', 'unitPrice' => 0.0025],
            ['meterName' => 'gpt-4o Output Tokens', 'unitPrice' => 0.0100],
            ['meterName' => 'gpt-4o-mini Input Tokens', 'unitPrice' => 0.00015],
            ['meterName' => 'gpt-4o-mini Output Tokens', 'unitPrice' => 0.00060],
        ])),
    ]);

    $this->artisan('ai:refresh-azure-prices')->assertSuccessful();

    $cached = Cache::get('azure_openai.prices.refreshed');
    expect($cached['gpt-4o'])->toMatchArray(['input_per_1m' => 2.50, 'output_per_1m' => 10.00, 'currency' => 'USD'])
        ->and($cached['gpt-4o-mini'])->toMatchArray(['input_per_1m' => 0.15, 'output_per_1m' => 0.60]);
});

it('does not match a gpt-4o-mini meter when pricing the gpt-4o deployment', function (): void {
    Http::fake([
        // The mini meter is listed FIRST, so a naive substring match would
        // mis-bill gpt-4o at the mini rate. Whole-token matching must skip it.
        'prices.azure.com/*' => Http::response(fakeRetailResponse([
            ['meterName' => 'gpt-4o-mini Input Tokens', 'unitPrice' => 0.00015],
            ['meterName' => 'gpt-4o-mini Output Tokens', 'unitPrice' => 0.00060],
            ['meterName' => 'gpt-4o Input Tokens', 'unitPrice' => 0.0025],
            ['meterName' => 'gpt-4o Output Tokens', 'unitPrice' => 0.0100],
        ])),
    ]);

    $this->artisan('ai:refresh-azure-prices')->assertSuccessful();

    $cached = Cache::get('azure_openai.prices.refreshed');
    expect($cached['gpt-4o'])->toMatchArray(['input_per_1m' => 2.50, 'output_per_1m' => 10.00]);
});

it('keeps the config seed rate for a deployment with no matching retail meter', function (): void {
    Http::fake([
        'prices.azure.com/*' => Http::response(fakeRetailResponse([
            ['meterName' => 'gpt-4o Input Tokens', 'unitPrice' => 0.0099],
            // No gpt-4o output meter, no gpt-4o-mini meters at all.
        ])),
    ]);

    $this->artisan('ai:refresh-azure-prices')->assertSuccessful();

    $cached = Cache::get('azure_openai.prices.refreshed');
    expect($cached['gpt-4o']['input_per_1m'])->toBe(9.90)        // refreshed from retail
        ->and($cached['gpt-4o']['output_per_1m'])->toBe(10.00)   // seed retained (no output meter)
        ->and($cached['gpt-4o-mini'])->toMatchArray(['input_per_1m' => 0.15, 'output_per_1m' => 0.60]); // seed retained
});

it('logs and keeps the config seed (no cache write) when the API call fails', function (): void {
    Log::spy();
    Http::fake(['prices.azure.com/*' => Http::response('upstream down', 500)]);

    $this->artisan('ai:refresh-azure-prices')->assertSuccessful();

    expect(Cache::has('azure_openai.prices.refreshed'))->toBeFalse();
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('azure_prices.refresh_failed', Mockery::on(fn (array $ctx): bool => isset($ctx['error'])));
});
