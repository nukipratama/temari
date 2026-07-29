<?php

declare(strict_types=1);

use App\Services\AI\Agent\AgentBudget;

it('allows tool steps while both ceilings are clear', function (): void {
    $budget = new AgentBudget(maxSteps: 3, maxTokens: 1000);
    $budget->recordStep(100, 50, 150);

    expect($budget->allowsToolStep())->toBeTrue()
        ->and($budget->exhaustedReason())->toBeNull();
});

it('stops allowing tool steps once the step ceiling is reached', function (): void {
    $budget = new AgentBudget(maxSteps: 2, maxTokens: 100000);
    $budget->recordStep(10, 5, 15);
    $budget->recordStep(10, 5, 15);

    expect($budget->allowsToolStep())->toBeFalse()
        ->and($budget->exhaustedReason())->toBe(AgentBudget::REASON_STEPS);
});

it('stops allowing tool steps once the token ceiling is reached', function (): void {
    $budget = new AgentBudget(maxSteps: 100, maxTokens: 500);
    $budget->recordStep(400, 150, 550);

    expect($budget->allowsToolStep())->toBeFalse()
        ->and($budget->exhaustedReason())->toBe(AgentBudget::REASON_TOKENS);
});

it('sums input, output and total tokens across every step', function (): void {
    $budget = new AgentBudget(maxSteps: 10, maxTokens: 100000);
    $budget->recordStep(100, 20, 120);
    $budget->recordStep(200, 30, 230);

    expect($budget->inputTokens())->toBe(300)
        ->and($budget->outputTokens())->toBe(50)
        ->and($budget->totalTokens())->toBe(350)
        ->and($budget->steps())->toBe(2);
});

it('derives a missing total from input plus output so an unreported step still counts', function (): void {
    $budget = new AgentBudget(maxSteps: 10, maxTokens: 100000);
    $budget->recordStep(100, 20, 0);

    expect($budget->totalTokens())->toBe(120);
});

it('reads its ceilings from config', function (): void {
    config()->set('ai.agent.max_steps', 4);
    config()->set('ai.agent.max_tokens', 9);

    $budget = AgentBudget::fromConfig();
    $budget->recordStep(5, 5, 10);

    expect($budget->exhaustedReason())->toBe(AgentBudget::REASON_TOKENS);
});

it('takes a per-narrator step ceiling over the config default, keeping the token one', function (): void {
    config()->set('ai.agent.max_steps', 8);
    config()->set('ai.agent.max_tokens', 30000);

    $budget = AgentBudget::fromConfig(maxSteps: 2);
    $budget->recordStep(5, 5, 10);

    expect($budget->exhaustedReason())->toBeNull();

    $budget->recordStep(5, 5, 10);

    expect($budget->exhaustedReason())->toBe(AgentBudget::REASON_STEPS);
});
