<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeWeeklyRecapJob;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\NarrationGate;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\SelfHealer;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

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

    $service = Mockery::mock(NarrationGate::class);
    $service->shouldReceive('generationPaused')->andReturn(true);
    $service->shouldReceive('pauseReason')->andReturn('cost_ceiling');
    $this->app->instance(NarrationGate::class, $service);

    $healer = Mockery::mock(SelfHealer::class);
    $healer->shouldNotReceive('run');
    $this->app->instance(SelfHealer::class, $healer);

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Skipped: AI generation is paused')
        ->assertSuccessful();
});

it('delegates the sweep to SelfHealer and prints the resumed count', function (): void {
    $service = Mockery::mock(NarrationGate::class);
    $service->shouldReceive('generationPaused')->andReturn(false);
    $service->shouldReceive('pauseReason')->andReturn(null);
    $this->app->instance(NarrationGate::class, $service);

    $healer = Mockery::mock(SelfHealer::class);
    $healer->shouldReceive('run')->once()->andReturn(4);
    $this->app->instance(SelfHealer::class, $healer);

    $this->artisan('ai:self-heal')
        ->expectsOutputToContain('Resumed 4 blocks.')
        ->assertSuccessful();

    expect(app(NarrationOrigin::class)->current())->toBe(AnalysisOrigin::Recovery);
});

it('gives a block that failed during a pause one fresh attempt when generation resumes, then dead-letters it again', function (): void {
    config(['azure_openai.uri' => 'https://x.openai.azure.com/x', 'azure_openai.api_key' => 'test-key']);
    Bus::fake();
    $config = app(AppConfig::class);

    Carbon::setTestNow('2026-06-17 08:00:00');
    $user = User::factory()->create(['last_seen_at' => Carbon::now()]);
    $longBefore = Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-06-07', 'runs' => 3])->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'status' => AnalysisStatus::Failed,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
        'updated_at' => Carbon::parse('2026-06-17 06:30:00'),
    ]);

    $config->set(AppConfigKey::AiEnabled, false);
    $this->artisan('ai:self-heal')->assertSuccessful();

    Carbon::setTestNow('2026-06-17 08:30:00');
    $during = Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-06-14', 'runs' => 3])->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'status' => AnalysisStatus::Failed,
        'attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS,
    ]);

    Carbon::setTestNow('2026-06-17 09:00:00');
    $config->set(AppConfigKey::AiEnabled, true);
    $this->artisan('ai:self-heal')->assertSuccessful();

    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);
    expect($during->fresh())
        ->status->toBe(AnalysisStatus::Queued)
        ->attempts->toBe(Analysis::MAX_SELF_HEAL_ATTEMPTS - 1)
        ->and($longBefore->fresh())
        ->status->toBe(AnalysisStatus::Failed)
        ->attempts->toBe(Analysis::MAX_SELF_HEAL_ATTEMPTS);

    $service = app(AnalysisService::class);
    $row = $during->fresh();
    $service->markProcessing($row);
    $service->markFailed($row, 'still broken');

    Carbon::setTestNow('2026-06-17 10:00:00');
    $this->artisan('ai:self-heal')->assertSuccessful();

    Bus::assertDispatchedTimes(AnalyzeWeeklyRecapJob::class, 1);
    expect(Analysis::query()->deadLettered()->whereKey($during->id)->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('does not treat the app-wide cost ceiling lifting as a resume', function (): void {
    $service = Mockery::mock(NarrationGate::class);
    $service->shouldReceive('generationPaused')->andReturn(false);
    $service->shouldReceive('pauseReason')->andReturn(null);
    $this->app->instance(NarrationGate::class, $service);
    app(AppConfig::class)->set(AppConfigKey::AiLastPauseReason, 'cost_ceiling');
    app(AppConfig::class)->set(AppConfigKey::AiPauseStartedAt, Carbon::now()->subHours(3)->toIso8601String());

    $healer = Mockery::mock(SelfHealer::class);
    $healer->shouldNotReceive('retryFailedDuringPause');
    $healer->shouldReceive('run')->once()->andReturn(0);
    $this->app->instance(SelfHealer::class, $healer);

    $this->artisan('ai:self-heal')->assertSuccessful();
});
