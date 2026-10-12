<?php

declare(strict_types=1);

use App\Console\Commands\HorizonRecommendAiProcessesCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('recommends one process per five athletes between the floor and the ceiling', function (int $athletes, int $recommended): void {
    expect(HorizonRecommendAiProcessesCommand::recommend($athletes))->toBe($recommended);
})->with([
    'the floor at zero athletes' => [0, 2],
    'the floor just under the first threshold' => [10, 2],
    'one more than the floor rounds up' => [11, 3],
    'rounding up at 25' => [25, 5],
    'the ceiling regardless of athlete count' => [1000, 6],
]);

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

    expect(Artisan::call('horizon:recommend-ai-processes'))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('Recommended HORIZON_AI_PROCESSES: 3');
    expect($output)->not->toContain('Configured value differs from the recommendation');
});
