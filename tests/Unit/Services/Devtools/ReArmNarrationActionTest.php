<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationOrigin;
use App\Services\Devtools\ReArmNarrationAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reArmFailedBlock(User $user, AnalysisType $type, int $attempts): Analysis
{
    return Analysis::factory()->create([
        'subject_type' => 'briefing_user_day',
        'subject_id' => $user->id,
        'analysis_type' => $type,
        'status' => AnalysisStatus::Failed,
        'attempts' => $attempts,
    ]);
}

it('re-arms every failed block for the athlete and re-dispatches without invalidating', function (): void {
    $user = User::factory()->create();
    $underBudget = reArmFailedBlock($user, AnalysisType::TrendRead, 1);
    $deadLettered = reArmFailedBlock($user, AnalysisType::WeeklyRecap, Analysis::MAX_SELF_HEAL_ATTEMPTS);

    $service = $this->mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->twice()
        ->withArgs(fn (...$args): bool => $args[4] === null && $args[5] === false);

    expect(app(ReArmNarrationAction::class)->retryFailed($user->id))->toBe(2);

    expect($underBudget->refresh()->attempts)->toBe(0)
        ->and($deadLettered->refresh()->attempts)->toBe(0);
});

it('re-arms only the dead-lettered blocks', function (): void {
    $user = User::factory()->create();
    $underBudget = reArmFailedBlock($user, AnalysisType::TrendRead, 1);
    reArmFailedBlock($user, AnalysisType::WeeklyRecap, Analysis::MAX_SELF_HEAL_ATTEMPTS);

    $this->mock(AnalysisService::class)->shouldReceive('request')->once();

    expect(app(ReArmNarrationAction::class)->reArmDeadLettered($user->id))->toBe(1)
        ->and($underBudget->refresh()->attempts)->toBe(1);
});

it('leaves another athlete\'s failed blocks alone', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    reArmFailedBlock($theirs, AnalysisType::TrendRead, 1);

    $this->mock(AnalysisService::class)->shouldNotReceive('request');

    expect(app(ReArmNarrationAction::class)->retryFailed($mine->id))->toBe(0);
});

it('declares the recovery origin so the re-dispatch is metered as one', function (): void {
    $user = User::factory()->create();
    reArmFailedBlock($user, AnalysisType::TrendRead, 1);
    $this->mock(AnalysisService::class)->shouldReceive('request');

    app(ReArmNarrationAction::class)->retryFailed($user->id);

    expect(app(NarrationOrigin::class)->current())->toBe(AnalysisOrigin::Recovery);
});

it('serves the demo athlete from the rule-based filler instead of billing a real re-dispatch', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $block = reArmFailedBlock($demo, AnalysisType::TrendRead, 1);

    $service = $this->mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $service->shouldReceive('requestRuleBased')
        ->once()
        ->withArgs(fn (...$args): bool => $args[0] === $block->subject_type
            && $args[1] === $block->subject_id
            && $args[2] === $block->analysis_type);

    expect(app(ReArmNarrationAction::class)->retryFailed($demo->id))->toBe(1);
});
