<?php

declare(strict_types=1);

use App\Console\Commands\HorizonRecommendAiProcessesCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('recommends the floor at zero athletes', function (): void {
    expect(HorizonRecommendAiProcessesCommand::recommend(0))->toBe(2);
});

it('recommends the floor just under the first threshold', function (): void {
    expect(HorizonRecommendAiProcessesCommand::recommend(10))->toBe(2);
});

it('recommends one process per five athletes, rounding up', function (): void {
    expect(HorizonRecommendAiProcessesCommand::recommend(11))->toBe(3)
        ->and(HorizonRecommendAiProcessesCommand::recommend(25))->toBe(5);
});

it('caps the recommendation at the ceiling regardless of athlete count', function (): void {
    expect(HorizonRecommendAiProcessesCommand::recommend(1000))->toBe(6);
});

it('prints the athlete count, recommendation and configured value', function (): void {
    config(['horizon.ai_processes' => 2]);
    User::factory()->count(11)->create();

    $this->artisan('horizon:recommend-ai-processes')
        ->assertSuccessful()
        ->expectsOutputToContain('Athletes: 11')
        ->expectsOutputToContain('Recommended HORIZON_AI_PROCESSES: 3')
        ->expectsOutputToContain('Currently configured: 2');
});

it('warns when the configured value no longer matches the recommendation', function (): void {
    config(['horizon.ai_processes' => 2]);
    User::factory()->count(11)->create();

    $this->artisan('horizon:recommend-ai-processes')
        ->assertSuccessful()
        ->expectsOutputToContain('Configured value differs from the recommendation');
});

it('stays quiet about drift when the configured value already matches', function (): void {
    config(['horizon.ai_processes' => 3]);
    User::factory()->count(11)->create();

    $this->artisan('horizon:recommend-ai-processes')
        ->assertSuccessful()
        ->doesntExpectOutputToContain('Configured value differs from the recommendation');
});
