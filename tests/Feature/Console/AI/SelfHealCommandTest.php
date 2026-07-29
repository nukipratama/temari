<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\SelfHealer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('early-exits without sweeping when AI generation is paused', function (): void {
    $user = User::factory()->create();
    $snap = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 3]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snap->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'discriminator' => null,
        'status' => AnalysisStatus::Pending,
    ]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('generationPaused')->andReturn(true);
    $service->shouldReceive('pauseReason')->andReturn('cost_ceiling');
    $this->app->instance(AnalysisService::class, $service);

    $healer = Mockery::mock(SelfHealer::class);
    $healer->shouldNotReceive('run');
    $this->app->instance(SelfHealer::class, $healer);

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Skipped: AI generation is paused')
        ->assertSuccessful();
});

it('delegates the sweep to SelfHealer and prints the resumed count', function (): void {
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('generationPaused')->andReturn(false);
    $service->shouldReceive('pauseReason')->andReturn(null);
    $this->app->instance(AnalysisService::class, $service);

    $healer = Mockery::mock(SelfHealer::class);
    $healer->shouldReceive('run')->once()->andReturn(4);
    $this->app->instance(SelfHealer::class, $healer);

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 4 blocks.')
        ->assertSuccessful();
});
