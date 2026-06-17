<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Capture every AnalysisService::request() call as a flat array of the args the
 * command cares about, returning a throwaway Analysis row.
 *
 * @param  array<int, array<string, mixed>>  $captured
 */
function captureWeeklyRequests(array &$captured): AnalysisService
{
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'delaySeconds', 'invalidate');

            return new Analysis();
        });

    return $service;
}

/** Stage a WeeklyRecap Analysis row at a given status for a snapshot. */
function stageWeeklyRecap(WeeklySnapshot $snapshot, AnalysisStatus $status): void
{
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $snapshot->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
        'status' => $status,
    ]);
}

it('narrates every completed week not yet Done, oldest first, with staggered delays and invalidate:false', function (): void {
    // Monday 2026-05-18; last completed week ends Sunday 2026-05-17.
    Carbon::setTestNow('2026-05-18 05:30:00');
    config()->set('ai.backfill_stagger_seconds', 100);

    $user = User::factory()->create();
    $oldest = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-04-26', 'runs' => 2]);
    $middle = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 3]);
    $latest = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureWeeklyRequests($captured));

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('Dispatched weekly recap for 3 snapshots (through week ending 2026-05-17)')
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

it('includes the demo user (no is_demo filter)', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');

    $real = User::factory()->create();
    $realSnap = WeeklySnapshot::factory()->for($real)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    $demo = User::factory()->demo()->create();
    $demoSnap = WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureWeeklyRequests($captured));

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('Dispatched weekly recap for 2 snapshots')
        ->assertSuccessful();

    expect(array_column($captured, 'subjectId'))
        ->toContain($realSnap->id)
        ->toContain($demoSnap->id);

    Carbon::setTestNow();
});

it('skips weeks whose recap is already Done and the open (in-progress) week', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');

    $user = User::factory()->create();
    // Already Done → must NOT be re-dispatched (no re-bill).
    $done = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 3]);
    stageWeeklyRecap($done, AnalysisStatus::Done);
    // Pending → narrate.
    $pending = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    stageWeeklyRecap($pending, AnalysisStatus::Pending);
    // Current in-progress week (ends next Sunday) → not completed yet, skip.
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 2]);
    // Zero-run week → nothing to narrate.
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 0]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureWeeklyRequests($captured));

    $this->artisan('ai:weekly-recap')->assertSuccessful();

    expect(array_column($captured, 'subjectId'))->toBe([$pending->id]);

    Carbon::setTestNow();
});

it('narrates a Failed and a far-back historical week (resume safety net)', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');

    $user = User::factory()->create();
    // Far-back week (months ago) whose recap Failed → still picked up.
    $farBack = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-01-04', 'runs' => 2]);
    stageWeeklyRecap($farBack, AnalysisStatus::Failed);
    $recent = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureWeeklyRequests($captured));

    $this->artisan('ai:weekly-recap')->assertSuccessful();

    expect(array_column($captured, 'subjectId'))->toBe([$farBack->id, $recent->id]);

    Carbon::setTestNow();
});

it('dispatches nothing when no completed week has runs', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('Dispatched weekly recap for 0 snapshots')
        ->assertSuccessful();

    Carbon::setTestNow();
});
