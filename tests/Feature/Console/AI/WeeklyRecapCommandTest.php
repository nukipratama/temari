<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('narrates every completed week not yet Done, oldest first, with staggered delays and invalidate:false', function (): void {
    // Monday 2026-05-18; last completed week ends Sunday 2026-05-17.
    Carbon::setTestNow('2026-05-18 05:30:00');
    config()->set('ai.backfill_stagger_seconds', 100);

    $user = User::factory()->create();
    $oldest = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-04-26', 'runs' => 2]);
    $middle = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 3]);
    $latest = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('Dispatched weekly recap for 3 snapshots (0 filled rule-based, 0 deferred for hydration) through week ending 2026-05-17')
        ->assertSuccessful();

    // Chronological order (oldest first) with index * stagger delays.
    expect($captured)->toHaveCount(3)
        ->and(array_column($captured, 'subjectId'))->toBe([$oldest->id, $middle->id, $latest->id])
        ->and(array_column($captured, 'delaySeconds'))->toBe([0, 100, 200])
        ->and(collect($captured)->pluck('invalidate')->every(fn (bool $i): bool => $i === false))->toBeTrue()
        ->and($captured[0]['type'])->toBe(AnalysisType::WeeklyRecap)
        ->and($captured[0]['subjectOrType'])->toBe(WeeklySnapshot::class);

    Carbon::setTestNow();
});

it('excludes the demo user so it never auto-bills the weekly LLM', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');

    $real = User::factory()->create();
    $realSnap = WeeklySnapshot::factory()->for($real)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    $demo = User::factory()->demo()->create();
    $demoSnap = WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('Dispatched weekly recap for 1 snapshots')
        ->assertSuccessful();

    expect(array_column($captured, 'subjectId'))
        ->not->toContain($demoSnap->id)
        ->toContain($realSnap->id);

    Carbon::setTestNow();
});
