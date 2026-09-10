<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\AI\AnalysisVersion;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\ServedBy;
use App\Services\Devtools\ReplayNarrationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'azure_openai.prices' => ['gpt-test' => ['input_per_1m' => 1000.0, 'output_per_1m' => 2000.0]],
        'azure_openai.replay_daily_cap' => 0.50,
    ]);
});

function replayBlock(User $user): Analysis
{
    return Analysis::factory()->done()->create([
        'subject_type' => 'briefing_user_day',
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
    ]);
}

function replaySpend(float $dollars): void
{
    // $1 per 1k prompt tokens under the price map above.
    TokenUsage::query()->create([
        'user_id' => null,
        'kind' => 'trend_read',
        'origin' => AnalysisOrigin::Replay,
        'prompt_tokens' => (int) round($dollars * 1000),
        'completion_tokens' => 0,
        'total_tokens' => (int) round($dollars * 1000),
        'model' => 'gpt-test',
        'created_at' => Carbon::now(),
    ]);
}

it('reports the cap and today\'s replay spend', function (): void {
    replaySpend(0.20);

    $action = app(ReplayNarrationAction::class);

    expect($action->cap())->toBe(0.5)
        ->and(round($action->spentToday(), 2))->toBe(0.2)
        ->and($action->capReached())->toBeFalse();
});

it('refuses at the cap, not past it', function (): void {
    replaySpend(0.50);

    expect(app(ReplayNarrationAction::class)->capReached())->toBeTrue();
});

it('never reaches a cap that is not configured', function (): void {
    config(['azure_openai.replay_daily_cap' => null]);
    replaySpend(9.0);

    $action = app(ReplayNarrationAction::class);

    expect($action->cap())->toBeNull()
        ->and($action->capReached())->toBeFalse();
});

it('counts only replay spend, not the athlete\'s own narration', function (): void {
    TokenUsage::query()->create([
        'user_id' => 1,
        'kind' => 'trend_read',
        'origin' => AnalysisOrigin::Scheduled,
        'prompt_tokens' => 900_000,
        'completion_tokens' => 0,
        'total_tokens' => 900_000,
        'model' => 'gpt-test',
        'created_at' => Carbon::now(),
    ]);

    expect(app(ReplayNarrationAction::class)->capReached())->toBeFalse();
});

it('re-dispatches the block with the replay origin, invalidating the done row', function (): void {
    $user = User::factory()->create();
    $block = replayBlock($user);

    $service = $this->mock(AnalysisService::class);
    $service->shouldReceive('shouldServeRuleBased')->andReturnFalse();
    $service->shouldReceive('request')->once()->withArgs(
        fn (...$args): bool => $args[0] === $block->subject_type
            && $args[1] === $block->subject_id
            && $args[2] === $block->analysis_type
            && $args[5] === true,
    );

    app(ReplayNarrationAction::class)->replay($block, $user);

    expect(app(NarrationOrigin::class)->current())->toBe(AnalysisOrigin::Replay);
});

it('serves the demo athlete from the rule-based filler, archiving the narration it replaces', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $block = replayBlock($demo);

    app(ReplayNarrationAction::class)->replay($block, $demo);

    $version = AnalysisVersion::query()->where('analysis_id', $block->id)->sole();

    expect($version->content)->toBe('Sample narrative')
        ->and($block->refresh()->served_by)->toBe(ServedBy::RuleBased)
        ->and($block->content)->not->toBe('Sample narrative')
        ->and(TokenUsage::query()->count())->toBe(0);
});
