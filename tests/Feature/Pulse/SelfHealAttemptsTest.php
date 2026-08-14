<?php

declare(strict_types=1);

use App\Livewire\Pulse\SelfHealAttempts;
use App\Models\AI\Analysis;
use App\Models\Activity;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function failedBlock(int $attempts, int $subjectId): Analysis
{
    return Analysis::factory()->failed('Azure timed out')->create([
        'subject_type' => Activity::class,
        'subject_id' => $subjectId,
        'analysis_type' => AnalysisType::PostRunSpeech,
        'attempts' => $attempts,
    ]);
}

it('renders the retry-budget snapshot without error', function (): void {
    Livewire::test(SelfHealAttempts::class)
        ->assertOk()
        ->assertSee('max '.Analysis::MAX_SELF_HEAL_ATTEMPTS.' attempts per block')
        ->assertSee('dead-letter');
});

it('shows an ok health badge when nothing has failed', function (): void {
    Livewire::test(SelfHealAttempts::class)
        ->assertOk()
        ->assertSee('health: ok');
});

it('shows the numeric attempts a failed block has burned against the cap', function (): void {
    failedBlock(1, 11);

    Livewire::test(SelfHealAttempts::class)
        ->assertOk()
        ->assertSee('Activity #11')
        ->assertSee('1/'.Analysis::MAX_SELF_HEAL_ATTEMPTS);
});

it('warns when a block is one attempt from dead-lettering', function (): void {
    failedBlock(Analysis::MAX_SELF_HEAL_ATTEMPTS - 1, 12);

    Livewire::test(SelfHealAttempts::class)
        ->assertOk()
        ->assertSee('health: warn');
});

it('alerts when a block has exhausted its retry budget', function (): void {
    failedBlock(Analysis::MAX_SELF_HEAL_ATTEMPTS, 13);

    Livewire::test(SelfHealAttempts::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('ignores a block that is still pending rather than failed', function (): void {
    Analysis::factory()->create([
        'subject_type' => Activity::class,
        'subject_id' => 14,
        'analysis_type' => AnalysisType::PostRunSpeech,
    ]);

    Livewire::test(SelfHealAttempts::class)
        ->assertOk()
        ->assertSee('health: ok')
        ->assertDontSee('Activity #14');
});
