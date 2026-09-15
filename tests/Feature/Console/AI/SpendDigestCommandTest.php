<?php

declare(strict_types=1);

use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\MaintainerAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeTime();

    config()->set('azure_openai.prices', [
        'gpt-4o' => ['input_per_1m' => 2.50, 'output_per_1m' => 10.00],
    ]);
    config()->set('azure_openai.daily_cost_ceiling_per_user', 1.0);
    config()->set('azure_openai.daily_cost_ceiling_total', 5.0);
});

function seedSpend(?int $userId, int $prompt, int $completion, ?Carbon $at = null): void
{
    TokenUsage::query()->create([
        'user_id' => $userId,
        'kind' => 'post_run_speech',
        'prompt_tokens' => $prompt,
        'completion_tokens' => $completion,
        'total_tokens' => $prompt + $completion,
        'cached_tokens' => 0,
        'reasoning_tokens' => 0,
        'steps' => 1,
        'model' => 'gpt-4o',
        'created_at' => $at ?? Carbon::now(),
    ]);
}

it('sends one digest with a line per athlete who spent today', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    seedSpend($first->id, 100_000, 10_000);
    seedSpend($first->id, 20_000, 1_000);
    seedSpend($second->id, 40_000, 2_000);
    seedSpend($second->id, 10_000, 1_000, Carbon::now()->subDay());

    $alerter = Mockery::mock(MaintainerAlerter::class);
    app()->instance(MaintainerAlerter::class, $alerter);

    $alerter->shouldReceive('spendDigest')->once()->withArgs(
        fn (array $rows, float $todayCost, ?float $perUser, ?float $total): bool => $rows === [
            ['userId' => $first->id, 'calls' => 2, 'tokens' => 131_000, 'cost' => 0.41],
            ['userId' => $second->id, 'calls' => 1, 'tokens' => 42_000, 'cost' => 0.12],
        ]
            && round($todayCost, 2) === 0.53
            && $perUser === 1.0
            && $total === 5.0,
    );

    $this->artisan('ai:spend-digest')->assertSuccessful();
});

it('sends a digest even on a day with no spend at all', function (): void {
    $alerter = Mockery::mock(MaintainerAlerter::class);
    app()->instance(MaintainerAlerter::class, $alerter);

    $alerter->shouldReceive('spendDigest')->once()->with([], 0.0, 1.0, 5.0);

    $this->artisan('ai:spend-digest')->assertSuccessful();
});

// Spend from an erased athlete keeps its row with a null user_id; it still
// belongs in the app-wide total, just not in a per-athlete line.
it('counts ownerless spend in the total without inventing an athlete line', function (): void {
    seedSpend(null, 40_000, 2_000);

    $alerter = Mockery::mock(MaintainerAlerter::class);
    app()->instance(MaintainerAlerter::class, $alerter);

    $alerter->shouldReceive('spendDigest')->once()->withArgs(
        fn (array $rows, float $todayCost): bool => $rows === [] && round($todayCost, 2) === 0.12,
    );

    $this->artisan('ai:spend-digest')->assertSuccessful();
});
