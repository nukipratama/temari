<?php

declare(strict_types=1);

use App\Services\AI\Agent\AgentBudget;
use Illuminate\Support\Carbon;

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

it('leaves the wall-clock deadline open when none is given', function (): void {
    Carbon::setTestNow('2026-09-09 06:00:00');
    $budget = new AgentBudget(maxSteps: 10, maxTokens: 100000);

    Carbon::setTestNow('2026-09-09 07:00:00');

    expect($budget->deadlinePassed())->toBeFalse();
});

it('passes its wall-clock deadline once the run has spent it', function (): void {
    Carbon::setTestNow('2026-09-09 06:00:00');
    $budget = new AgentBudget(maxSteps: 10, maxTokens: 100000, deadlineSeconds: 240);

    Carbon::setTestNow(Carbon::now()->addSeconds(239));
    expect($budget->deadlinePassed())->toBeFalse();

    Carbon::setTestNow(Carbon::now()->addSecond());
    expect($budget->deadlinePassed())->toBeTrue();
});

it('reads the deadline from config', function (): void {
    Carbon::setTestNow('2026-09-09 06:00:00');
    config()->set('ai.agent.max_steps', 10);
    config()->set('ai.agent.max_tokens', 30000);
    config()->set('ai.agent.deadline_seconds', 30);

    $budget = AgentBudget::fromConfig();

    Carbon::setTestNow(Carbon::now()->addSeconds(31));

    expect($budget->deadlinePassed())->toBeTrue()
        ->and($budget->exhaustedReason())->toBeNull();
});

it('accumulates the tool trace in call order, truncating the arguments summary', function (): void {
    $budget = new AgentBudget(maxSteps: 5, maxTokens: 1000);

    $budget->recordToolCall('get_thing', '{"id":1}', 12);
    $budget->recordToolCall('get_other', str_repeat('x', 500), 3);

    expect($budget->toolCalls())->toHaveCount(2)
        ->and($budget->toolCalls()[0])->toBe([
            'tool' => 'get_thing',
            'arguments_summary' => '{"id":1}',
            'duration_ms' => 12,
        ])
        ->and($budget->toolCalls()[1]['arguments_summary'])
        ->toHaveLength(AgentBudget::ARGUMENTS_SUMMARY_LENGTH);
});

it('starts with an empty tool trace', function (): void {
    expect(new AgentBudget(maxSteps: 5, maxTokens: 1000)->toolCalls())->toBe([]);
});
