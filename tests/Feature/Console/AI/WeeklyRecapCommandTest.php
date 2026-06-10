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
        ->expectsOutputToContain('Dispatched weekly recap for 1 snapshots (week ending 2026-05-17)')
        ->assertSuccessful();

    expect($captured)->toHaveCount(1)
        ->and($captured[0]['subjectOrType'])->toBe(WeeklySnapshot::class)
        ->and($captured[0]['subjectId'])->toBe($ranLastWeek->id)
        ->and($captured[0]['type'])->toBe(AnalysisType::WeeklyRecap)
        ->and($captured[0]['invalidate'])->toBeTrue();

    Carbon::setTestNow();
});

it('self-heals a stalled prior-week recap (Pending) without touching Done ones', function (): void {
    // Monday 2026-05-18; last completed week 2026-05-17; sweep covers ~3 weeks back.
    Carbon::setTestNow('2026-05-18 05:30:00');
    $user = User::factory()->create();

    // Just-closed week → primary pass (invalidate:true).
    $thisWeek = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    // Prior week whose recap STALLED (Pending) → should self-heal (invalidate:false).
    $stalled = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 2]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class, 'subject_id' => $stalled->id,
        'analysis_type' => AnalysisType::WeeklyRecap, 'status' => AnalysisStatus::Pending,
    ]);
    // Prior week already Done → must NOT be re-dispatched (no re-bill).
    $done = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 4]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class, 'subject_id' => $done->id,
        'analysis_type' => AnalysisType::WeeklyRecap, 'status' => AnalysisStatus::Done,
    ]);
    // Oldest week the 3-week window still covers (week_ending = 2026-05-17 - 3w).
    $oldestIncluded = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-04-26', 'runs' => 2]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class, 'subject_id' => $oldestIncluded->id,
        'analysis_type' => AnalysisType::WeeklyRecap, 'status' => AnalysisStatus::Failed,
    ]);
    // One week older → outside the window, must be left stranded by the sweep.
    $tooOld = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-04-19', 'runs' => 2]);
    Analysis::factory()->create([
        'subject_type' => WeeklySnapshot::class, 'subject_id' => $tooOld->id,
        'analysis_type' => AnalysisType::WeeklyRecap, 'status' => AnalysisStatus::Pending,
    ]);

    $captured = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = compact('subjectId', 'invalidate');

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('re-dispatched 2 stalled')
        ->assertSuccessful();

    $byId = collect($captured)->keyBy('subjectId');
    expect($byId)->toHaveCount(3)
        ->and($byId[$thisWeek->id]['invalidate'])->toBeTrue()        // primary, final data
        ->and($byId[$stalled->id]['invalidate'])->toBeFalse()        // self-heal, no re-bill
        ->and($byId[$oldestIncluded->id]['invalidate'])->toBeFalse() // window lower bound
        ->and($byId->has($done->id))->toBeFalse()                    // Done left alone
        ->and($byId->has($tooOld->id))->toBeFalse();                 // outside the window

    Carbon::setTestNow();
});

it('skips the demo user\'s snapshot', function (): void {
    Carbon::setTestNow('2026-05-18 05:30:00');

    $real = User::factory()->create();
    $realSnap = WeeklySnapshot::factory()->for($real)->create(['week_ending' => '2026-05-17', 'runs' => 3]);
    $demo = User::factory()->demo()->create();
    WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-17', 'runs' => 3]);

    $captured = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = $subjectId;

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:weekly-recap')
        ->expectsOutputToContain('Dispatched weekly recap for 1 snapshots')
        ->assertSuccessful();

    expect($captured)->toBe([$realSnap->id]);

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
