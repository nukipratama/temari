<?php

declare(strict_types=1);

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('dispatches one recalibration for a real user and excludes demo users', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $demo = User::factory()->demo()->create();

    PlanRecalibrationDispatch::forUserId($user->id);
    PlanRecalibrationDispatch::forUserId($demo->id);

    Bus::assertDispatchedTimes(RecalibrateTrainingHistoryJob::class, 1);
    Bus::assertDispatched(
        RecalibrateTrainingHistoryJob::class,
        fn (RecalibrateTrainingHistoryJob $job): bool => $job->userId === $user->id,
    );
    expect($user->fresh()->plan_recalibration_started_at)->not->toBeNull()
        ->and($user->fresh()->plan_recalibration_completed_at)->toBeNull();
});

it('can suppress recursive dispatch while recalibration updates zone-derived data', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    PlanRecalibrationDispatch::withoutDispatching(
        fn () => PlanRecalibrationDispatch::forUserId($user->id),
    );

    Bus::assertNothingDispatched();
});

it('marks an in-progress recalibration dirty without re-arming its progress stamp', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    PlanRecalibrationDispatch::forUserId($user->id);
    $startedAt = $user->fresh()->plan_recalibration_started_at;
    $lock = Cache::lock(
        RecalibrateTrainingHistoryJob::overlapLockKey($user->id),
        RecalibrateTrainingHistoryJob::overlapLockTtlSeconds(),
    );
    expect($lock->get())->toBeTrue();

    PlanRecalibrationDispatch::forUserId($user->id);

    Bus::assertDispatchedTimes(RecalibrateTrainingHistoryJob::class, 1);
    expect($user->fresh()->plan_recalibration_started_at->toIso8601String())
        ->toBe($startedAt->toIso8601String())
        ->and(Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id)))->toBeTrue();

    $lock->release();
});
