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
    expect(new LlmCostCalculator()->costFor('gpt-4o', 1_000_000, 1_000_000))->toBe(12.50);
});

it('scales cost proportionally for sub-million token counts', function (): void {
    expect(new LlmCostCalculator()->costFor('gpt-4o', 500, 200))->toEqualWithDelta(0.00325, 1e-9);
});

it('prices a deployment directly from the config rate map', function (): void {
    config()->set('azure_openai.prices', ['nuki-5.2' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]);

    expect(new LlmCostCalculator()->costFor('nuki-5.2', 1_000_000, 1_000_000))->toBe(12.50);
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

    expect(new LlmCostCalculator()->dailyCost())->toBe(2.65);
});

it('returns zero daily cost when there is no usage today', function (): void {
    expect(new LlmCostCalculator()->dailyCost())->toBe(0.0);
});

it('exposes the configured rate and null for an unconfigured deployment', function (): void {
    config()->set('azure_openai.prices', ['nuki-5.2' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]);

    expect(new LlmCostCalculator()->priceFor('nuki-5.2'))->toMatchArray(['input_per_1m' => 2.50, 'output_per_1m' => 10.00])
        ->and(new LlmCostCalculator()->priceFor('mystery'))->toBeNull();
});

// Both deployments bill cached input at a tenth of their input rate, and prod
// was already serving 43-62% of input from cache before these were configured,
// so the estimate had been overstating spend.
it('bills cached input at the configured discount for the real deployments', function (): void {
    config()->set('azure_openai.prices', [
        'nuki-5.4-mini' => ['input_per_1m' => 0.75, 'cached_input_per_1m' => 0.075, 'output_per_1m' => 4.50],
    ]);

    $calculator = app(LlmCostCalculator::class);

    // 1M input of which 600k cached, 100k output.
    // (0.4 * 0.75) + (0.6 * 0.075) + (0.1 * 4.50) = 0.3 + 0.045 + 0.45
    expect(round($calculator->costFor('nuki-5.4-mini', 1_000_000, 100_000, 600_000), 4))
        ->toBe(0.795)
        // Same tokens, none cached, costs more.
        ->and(round($calculator->costFor('nuki-5.4-mini', 1_000_000, 100_000, 0), 4))
        ->toBe(1.2);
});

it('bills cached input as ordinary input when a deployment declares no cached rate', function (): void {
    config()->set('azure_openai.prices', [
        'legacy' => ['input_per_1m' => 1.00, 'output_per_1m' => 2.00],
    ]);

    $calculator = app(LlmCostCalculator::class);

    // A missing cached rate must never understate: cached and uncached cost the same.
    expect($calculator->costFor('legacy', 1_000_000, 0, 1_000_000))
        ->toBe($calculator->costFor('legacy', 1_000_000, 0, 0));
});
