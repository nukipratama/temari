<?php

declare(strict_types=1);

use App\Models\AI\TokenUsage;
use App\Services\AI\AzureRetailPrices;
use App\Services\AI\LlmCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.deployments', []);
    config()->set('azure_openai.price_cache_key', 'azure_openai.prices.refreshed');
    Cache::forget('azure_openai.prices.refreshed');
});

/** A calculator whose retail fetch returns $map (or throws when $map is a Throwable). */
function calculatorWithRetail(array|Throwable $map = []): LlmCostCalculator
{
    $retail = Mockery::mock(AzureRetailPrices::class);
    $expectation = $retail->shouldReceive('fetch');
    $map instanceof Throwable ? $expectation->andThrow($map) : $expectation->andReturn($map);

    return new LlmCostCalculator($retail);
}

$gpt4o = ['input_per_1m' => 2.50, 'output_per_1m' => 10.00, 'currency' => 'USD'];

it('costs a call from the retail price map', function () use ($gpt4o): void {
    // 1M input @ 2.50 + 1M output @ 10.00 = 12.50
    expect(calculatorWithRetail(['gpt-4o' => $gpt4o])->costFor('gpt-4o', 1_000_000, 1_000_000))->toBe(12.50);
});

it('scales cost proportionally for sub-million token counts', function () use ($gpt4o): void {
    expect(calculatorWithRetail(['gpt-4o' => $gpt4o])->costFor('gpt-4o', 500, 200))->toEqualWithDelta(0.00325, 1e-9);
});

it('maps an arbitrary deployment name to its model for pricing', function () use ($gpt4o): void {
    config()->set('azure_openai.deployments', ['nuki-5.2' => 'gpt-4o']);

    // nuki-5.2 is priced as gpt-4o.
    expect(calculatorWithRetail(['gpt-4o' => $gpt4o])->costFor('nuki-5.2', 1_000_000, 1_000_000))->toBe(12.50);
});

it('uses the cached price map without fetching', function (): void {
    Cache::forever('azure_openai.prices.refreshed', [
        'gpt-4o' => ['input_per_1m' => 5.00, 'output_per_1m' => 20.00, 'currency' => 'USD'],
    ]);

    $retail = Mockery::mock(AzureRetailPrices::class);
    $retail->shouldNotReceive('fetch');

    expect((new LlmCostCalculator($retail))->costFor('gpt-4o', 1_000_000, 1_000_000))->toBe(25.00);
});

it('fetches the retail map on a cold cache and caches it', function (): void {
    $calculator = calculatorWithRetail([
        'gpt-4o' => ['input_per_1m' => 5.00, 'output_per_1m' => 20.00, 'currency' => 'USD'],
    ]);

    expect($calculator->costFor('gpt-4o', 1_000_000, 1_000_000))->toBe(25.00)
        ->and(Cache::has('azure_openai.prices.refreshed'))->toBeTrue();
});

it('costs 0 and does not cache when the retail fetch fails', function (): void {
    $calculator = calculatorWithRetail(new RuntimeException('retail down'));

    expect($calculator->costFor('gpt-4o', 1_000_000, 1_000_000))->toBe(0.0)
        ->and(Cache::has('azure_openai.prices.refreshed'))->toBeFalse();
});

it('costs 0.0 and warns once for a model with no rate', function () use ($gpt4o): void {
    Log::spy();
    $calculator = calculatorWithRetail(['gpt-4o' => $gpt4o]);

    expect($calculator->costFor('mystery-model', 1_000_000, 1_000_000))->toBe(0.0)
        ->and($calculator->costFor('mystery-model', 1, 1))->toBe(0.0);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('llm_cost.unknown_deployment', ['deployment' => 'mystery-model']);
});

it('sums today\'s cost per deployment across the analytics table', function () use ($gpt4o): void {
    $now = Carbon::today()->setTime(10, 0);
    TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => $now,
    ]); // 2.50
    TokenUsage::query()->create([
        'kind' => 'run-insight', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o-mini', 'created_at' => $now,
    ]); // 0.15
    TokenUsage::query()->create([
        'kind' => 'briefing', 'prompt_tokens' => 1_000_000, 'completion_tokens' => 0,
        'total_tokens' => 1_000_000, 'model' => 'gpt-4o', 'created_at' => Carbon::yesterday()->setTime(10, 0),
    ]); // excluded: yesterday

    $rates = ['gpt-4o' => $gpt4o, 'gpt-4o-mini' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60, 'currency' => 'USD']];

    expect(calculatorWithRetail($rates)->dailyCost())->toBe(2.65);
});

it('returns zero daily cost when there is no usage today', function (): void {
    expect(calculatorWithRetail()->dailyCost())->toBe(0.0);
});

it('reports refreshed prices once the cache is populated, unavailable otherwise', function (): void {
    expect(calculatorWithRetail()->isUsingRefreshedPrices())->toBeFalse();

    Cache::forever('azure_openai.prices.refreshed', ['gpt-4o' => ['input_per_1m' => 1.0, 'output_per_1m' => 1.0, 'currency' => 'USD']]);

    expect(calculatorWithRetail()->isUsingRefreshedPrices())->toBeTrue();
});
