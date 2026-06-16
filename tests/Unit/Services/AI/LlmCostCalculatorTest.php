<?php

declare(strict_types=1);

use App\Models\AI\TokenUsage;
use App\Services\AI\LlmCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.prices', [
        'gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00, 'currency' => 'USD'],
        'gpt-4o-mini' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60, 'currency' => 'USD'],
    ]);
    config()->set('azure_openai.price_cache_key', 'azure_openai.prices.refreshed');
    Cache::forget('azure_openai.prices.refreshed');

    $this->calculator = new LlmCostCalculator();
});

it('costs a call from the config price seed', function (): void {
    // 1M input @ 2.50 + 1M output @ 10.00 = 12.50
    expect($this->calculator->costFor('gpt-4o', 1_000_000, 1_000_000))->toBe(12.50);
});

it('scales cost proportionally for sub-million token counts', function (): void {
    // 500 in @ 2.50/1M + 200 out @ 10.00/1M = 0.00125 + 0.002 = 0.00325
    expect($this->calculator->costFor('gpt-4o', 500, 200))->toEqualWithDelta(0.00325, 1e-9);
});

it('prefers the refreshed cache price map over the config seed', function (): void {
    Cache::forever('azure_openai.prices.refreshed', [
        'gpt-4o' => ['input_per_1m' => 5.00, 'output_per_1m' => 20.00, 'currency' => 'USD'],
    ]);

    // 1M in @ 5.00 + 1M out @ 20.00 = 25.00 (cache wins over the 12.50 seed).
    expect($this->calculator->costFor('gpt-4o', 1_000_000, 1_000_000))->toBe(25.00);
});

it('returns 0.0 and warns once for an unknown deployment', function (): void {
    Log::spy();

    expect($this->calculator->costFor('mystery-model', 1_000_000, 1_000_000))->toBe(0.0)
        ->and($this->calculator->costFor('mystery-model', 1, 1))->toBe(0.0);

    // Warned once for the deployment despite two calls.
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('llm_cost.unknown_deployment', ['deployment' => 'mystery-model']);
});

it('sums today\'s cost per deployment across the analytics table', function (): void {
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

    expect($this->calculator->dailyCost())->toBe(2.65);
});

it('returns zero daily cost when there is no usage today', function (): void {
    expect($this->calculator->dailyCost())->toBe(0.0);
});

it('reports whether the refreshed price cache is populated', function (): void {
    expect($this->calculator->isUsingRefreshedPrices())->toBeFalse();

    Cache::forever('azure_openai.prices.refreshed', ['gpt-4o' => ['input_per_1m' => 1.0, 'output_per_1m' => 1.0, 'currency' => 'USD']]);

    expect($this->calculator->isUsingRefreshedPrices())->toBeTrue();
});
