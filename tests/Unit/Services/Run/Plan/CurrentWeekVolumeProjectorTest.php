<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Plan\CurrentWeekVolumeProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-13 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('keeps the activity read when the current week has no sessions', function (): void {
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $weekStart->copy()->setTime(6, 0),
        'distance' => 8_000.0,
    ]);

    $projection = app(CurrentWeekVolumeProjector::class)->project(
        $user,
        collect(),
        $weekStart,
        Carbon::today(),
        10.0,
        1.0,
        INF,
        INF,
        null,
        null,
        null,
    );

    expect($projection['scale_by_date'])->toBe([])
        ->and($projection['activity_by_date']['2026-08-10']['meters'])->toBe(8_000.0);
});

it('reduces future easy volume for earlier surplus while preserving pinned and key sessions', function (): void {
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);

    foreach ([
        [0, SessionType::Easy, false],
        [1, SessionType::Rest, false],
        [2, SessionType::Rest, false],
        [3, SessionType::Rest, false],
        [4, SessionType::Easy, true],
        [5, SessionType::Easy, false],
        [6, SessionType::Long, false],
    ] as [$offset, $type, $pinned]) {
        PlannedSession::factory()->for($user)->create([
            'date' => $weekStart->copy()->addDays($offset),
            'phase' => PlanPhase::Base,
            'session_type' => $type,
            'pinned' => $pinned,
            'volume_multiplier' => 1.0,
        ]);
    }

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $weekStart->copy()->setTime(6, 0),
        'distance' => 20_000.0,
    ]);

    $sessions = PlannedSession::query()->where('user_id', $user->id)->orderBy('date')->get();
    $todaySession = $sessions->first(fn (PlannedSession $session): bool => $session->date->isSameDay(Carbon::today()));
    $saturday = $weekStart->copy()->addDays(5)->toDateString();
    $friday = $weekStart->copy()->addDays(4)->toDateString();
    $sunday = $weekStart->copy()->addDays(6)->toDateString();

    $projection = app(CurrentWeekVolumeProjector::class)->project(
        $user,
        $sessions,
        $weekStart,
        Carbon::today(),
        10.0,
        1.0,
        INF,
        INF,
        $weekStart->toDateString(),
        $todaySession,
        null,
    );
    $scales = $projection['scale_by_date'];

    expect($scales[$saturday])->toBe(0.7)
        ->and($scales)->not->toHaveKey($friday)
        ->and($scales)->not->toHaveKey($sunday);
});
