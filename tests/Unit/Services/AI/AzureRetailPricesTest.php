<?php

declare(strict_types=1);

use App\Services\AI\AzureRetailPrices;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

function retailItems(array $meters): array
{
    return ['Items' => array_map(
        fn (array $m): array => ['meterName' => $m[0], 'unitPrice' => $m[1]],
        $meters,
    )];
}

it('maps configured models to their per-1M retail rates', function (): void {
    Http::fake([
        'prices.azure.com/*' => Http::response(retailItems([
            ['gpt-4o Input Tokens', 0.0025],
            ['gpt-4o Output Tokens', 0.0100],
            ['gpt-4o-mini Input Tokens', 0.00015],
            ['gpt-4o-mini Output Tokens', 0.00060],
        ])),
    ]);

    $map = (new AzureRetailPrices())->fetch(['gpt-4o', 'gpt-4o-mini']);

    expect($map['gpt-4o'])->toMatchArray(['input_per_1m' => 2.50, 'output_per_1m' => 10.00])
        ->and($map['gpt-4o-mini'])->toMatchArray(['input_per_1m' => 0.15, 'output_per_1m' => 0.60]);
});

it('does not match a gpt-4o-mini meter when pricing gpt-4o', function (): void {
    Http::fake([
        'prices.azure.com/*' => Http::response(retailItems([
            ['gpt-4o-mini Input Tokens', 0.00015],
            ['gpt-4o-mini Output Tokens', 0.00060],
            ['gpt-4o Input Tokens', 0.0025],
            ['gpt-4o Output Tokens', 0.0100],
        ])),
    ]);

    $map = (new AzureRetailPrices())->fetch(['gpt-4o']);

    expect($map['gpt-4o'])->toMatchArray(['input_per_1m' => 2.50, 'output_per_1m' => 10.00]);
});

it('omits a model with no matching retail meter', function (): void {
    Http::fake([
        'prices.azure.com/*' => Http::response(retailItems([
            ['gpt-4o Input Tokens', 0.0025],
            ['gpt-4o Output Tokens', 0.0100],
        ])),
    ]);

    $map = (new AzureRetailPrices())->fetch(['gpt-4o', 'o3-pro']);

    expect($map)->toHaveKey('gpt-4o')->and($map)->not->toHaveKey('o3-pro');
});

it('returns an empty map without calling the API for no models', function (): void {
    Http::fake();

    expect((new AzureRetailPrices())->fetch([]))->toBe([]);
    Http::assertNothingSent();
});

it('throws when the retail API call fails', function (): void {
    Http::fake(['prices.azure.com/*' => Http::response('boom', 500)]);

    expect(fn () => (new AzureRetailPrices())->fetch(['gpt-4o']))->toThrow(RequestException::class);
});
