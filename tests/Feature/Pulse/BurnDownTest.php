<?php

declare(strict_types=1);

use App\Livewire\Pulse\BurnDown;
use App\Models\AI\TokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function spendToday(float $inputMillions): void
{
    config(['azure_openai.prices' => ['gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00]]]);

    TokenUsage::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => 'briefing',
        'prompt_tokens' => (int) ($inputMillions * 1_000_000),
        'completion_tokens' => 0,
        'total_tokens' => (int) ($inputMillions * 1_000_000),
        'model' => 'gpt-4o',
        'created_at' => now(),
    ]);
}

it('renders both budgets without error', function (): void {
    Livewire::test(BurnDown::class)
        ->assertOk()
        ->assertSee('LLM spend today')
        ->assertSee('Strava reads, 15 min')
        ->assertSee('Strava reads, today');
});

it('says so when no app-wide LLM ceiling is configured', function (): void {
    config(['azure_openai.daily_cost_ceiling_total' => null]);

    Livewire::test(BurnDown::class)
        ->assertOk()
        ->assertSee('no ceiling configured');
});

it('shows today spend as a share of the app-wide ceiling', function (): void {
    config(['azure_openai.daily_cost_ceiling_total' => 5.0]);
    spendToday(1.0);

    Livewire::test(BurnDown::class)
        ->assertOk()
        ->assertSee('50% spent');
});

it('alerts once the app-wide ceiling is spent', function (): void {
    config(['azure_openai.daily_cost_ceiling_total' => 2.0]);
    spendToday(1.0);

    Livewire::test(BurnDown::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('counts the shared Strava read budget as spent, not as remaining', function (): void {
    for ($i = 0; $i < 5; $i++) {
        RateLimiter::hit('strava-api:15min', 900);
    }

    Livewire::test(BurnDown::class)
        ->assertOk()
        ->assertSee('2.5% spent');
});
