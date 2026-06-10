<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('dispatches the weekly recap once per last-completed-week snapshot with runs', function (): void {
    // Monday 2026-05-18; last completed week ends Sunday 2026-05-17.
    Carbon::setTestNow('2026-05-18 05:30:00');

    $userA = User::factory()->create();
    $ranLastWeek = WeeklySnapshot::factory()->for($userA)->create([
        'week_ending' => '2026-05-17',
        'runs' => 3,
    ]);
    $userB = User::factory()->create();
    WeeklySnapshot::factory()->for($userB)->create([
        'week_ending' => '2026-05-17',
        'runs' => 0, // CTL-continuity row, nothing to narrate
    ]);
    WeeklySnapshot::factory()->for($userA)->create([
        'week_ending' => '2026-05-10', // older week, already handled
        'runs' => 2,
    ]);

    $captured = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'invalidate');

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('Dispatched weekly recap for 1 snapshots (week ending 2026-05-17).')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(WeeklySnapshot::class)
        ->and($captured[0]['subjectId'])->toBe($ranLastWeek->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::WeeklyRecap)
        ->and($captured[0]['invalidate'])->toBeTrue();

    Carbon::setTestNow();
});

it('dispatches nothing when last week has no snapshots with runs', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('Dispatched weekly recap for 0 snapshots')
        ->assertSuccessful();

    Carbon::setTestNow();
});
