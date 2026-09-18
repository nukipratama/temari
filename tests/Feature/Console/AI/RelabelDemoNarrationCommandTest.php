<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisType;
use App\Services\AI\ServedBy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A Done, llm-stamped WeeklyRecap owned by $user. */
function llmStampedRecapFor(User $user): Analysis
{
    $snap = WeeklySnapshot::factory()->for($user)->create();

    return Analysis::factory()->done('a narration')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'served_by' => ServedBy::Llm,
    ]);
}

it('restamps a demo row that no token usage ever paid for', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $row = llmStampedRecapFor($demo);

    $this->artisan('ai:relabel-demo-narration')
        ->expectsOutputToContain('Relabelled 1 demo rows')
        ->assertSuccessful();

    expect($row->fresh()->served_by)->toBe(ServedBy::RuleBased)
        ->and($row->fresh()->rule_based_reason)->toBe(AnalysisOrigin::Demo);
});

it('leaves a non-demo row alone', function (): void {
    $row = llmStampedRecapFor(User::factory()->create(['is_demo' => false]));

    $this->artisan('ai:relabel-demo-narration')->assertSuccessful();

    expect($row->fresh()->served_by)->toBe(ServedBy::Llm);
});

it('leaves a demo row that a recorded token usage references', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    $row = llmStampedRecapFor($demo);
    TokenUsage::query()->create([
        'user_id' => $demo->id,
        'analysis_id' => $row->id,
        'kind' => AnalysisType::WeeklyRecap->value,
        'prompt_tokens' => 100,
        'completion_tokens' => 20,
        'total_tokens' => 120,
        'model' => 'gpt-4o',
    ]);

    $this->artisan('ai:relabel-demo-narration')
        ->expectsOutputToContain('Relabelled 0 demo rows')
        ->expectsOutputToContain('Left 1 demo rows stamped llm')
        ->assertSuccessful();

    expect($row->fresh()->served_by)->toBe(ServedBy::Llm);
});

it('is idempotent across re-runs', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);
    llmStampedRecapFor($demo);

    $this->artisan('ai:relabel-demo-narration')->assertSuccessful();
    $this->artisan('ai:relabel-demo-narration')
        ->expectsOutputToContain('No demo rows stamped llm.')
        ->assertSuccessful();
});

it('says so when there is no demo athlete', function (): void {
    $this->artisan('ai:relabel-demo-narration')
        ->expectsOutputToContain('No demo athlete; nothing to relabel.')
        ->assertSuccessful();
});
