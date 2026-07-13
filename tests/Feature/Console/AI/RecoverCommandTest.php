<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/** A dead-lettered (Failed, budget burned) WeeklyRecap for $user. */
function deadLetteredWeeklyRecapFor(User $user): Analysis
{
    $snap = WeeklySnapshot::factory()->for($user)->create();

    return Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);
}

it('re-arms and re-dispatches every dead-lettered block, then runs self-heal', function (): void {
    Bus::fake();
    $row = deadLetteredWeeklyRecapFor(User::factory()->create());

    $this->artisan('ai:recover')
        ->expectsOutputToContain('Re-armed 1 dead-lettered blocks.')
        ->expectsOutputToContain('Resumed')
        ->assertSuccessful();

    $fresh = $row->fresh();
    expect($fresh->attempts)->toBe(0)
        ->and($fresh->status)->toBe(AnalysisStatus::Queued);
    Bus::assertDispatched(AnalyzeWeeklyRecapJob::class);
});

it('never re-arms a demo user (no auto-bill on the shared demo account)', function (): void {
    Bus::fake();
    $demoRow = deadLetteredWeeklyRecapFor(User::factory()->demo()->create());

    $this->artisan('ai:recover')
        ->expectsOutputToContain('Re-armed 0 dead-lettered blocks.')
        ->assertSuccessful();

    $fresh = $demoRow->fresh();
    expect($fresh->attempts)->toBe(Analysis::MAX_SELF_HEAL_ATTEMPTS)
        ->and($fresh->status)->toBe(AnalysisStatus::Failed);
});

it('leaves under-budget Failed blocks for self-heal (only dead-letters are re-armed here)', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create();
    $underBudget = Analysis::factory()->failed()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'attempts' => 1,
    ]);

    $this->artisan('ai:recover')
        ->expectsOutputToContain('Re-armed 0 dead-lettered blocks.')
        ->assertSuccessful();

    // Still attempts=1: the recover re-arm only touches dead-lettered rows; the
    // self-heal sweep resumes it separately without resetting the budget.
    expect($underBudget->fresh()->attempts)->toBe(1);
});
