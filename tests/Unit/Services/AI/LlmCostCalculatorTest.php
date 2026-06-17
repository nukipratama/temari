<?php

declare(strict_types=1);

use App\Models\AI\TokenUsage;
use App\Services\AI\LlmCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('azure_openai.prices', [
        'gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00],
        'gpt-4o-mini' => ['input_per_1m' => 0.15, 'output_per_1m' => 0.60],
    ]);
});

it('costs a call from the config price map', function (): void {
    // 1M input @ 2.50 + 1M output @ 10.00 = 12.50
    expect((new LlmCostCalculator())->costFor('gpt-4o', 1_000_000, 1_000_000))->toBe(12.50);
});

it('scales cost proportionally for sub-million token counts', function (): void {
    expect((new LlmCostCalculator())->costFor('gpt-4o', 500, 200))->toEqualWithDelta(0.00325, 1e-9);
});

it('prices a deployment directly from the config rate map', function (): void {
    config()->set('azure_openai.prices', ['nuki-5.2' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]);

    expect((new LlmCostCalculator())->costFor('nuki-5.2', 1_000_000, 1_000_000))->toBe(12.50);
});

it('returns 0.0 and warns once for a model with no configured rate', function (): void {
    Log::spy();
    $calculator = new LlmCostCalculator();

    expect($calculator->costFor('mystery-model', 1_000_000, 1_000_000))->toBe(0.0)
        ->and($calculator->costFor('mystery-model', 1, 1))->toBe(0.0);

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

    expect((new LlmCostCalculator())->dailyCost())->toBe(2.65);
});

it('returns zero daily cost when there is no usage today', function (): void {
    expect((new LlmCostCalculator())->dailyCost())->toBe(0.0);
});

it('exposes the configured rate and null for an unconfigured deployment', function (): void {
    config()->set('azure_openai.prices', ['nuki-5.2' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]);

    expect((new LlmCostCalculator())->priceFor('nuki-5.2'))->toMatchArray(['input_per_1m' => 2.50, 'output_per_1m' => 10.00])
        ->and((new LlmCostCalculator())->priceFor('mystery'))->toBeNull();
});
