<?php

declare(strict_types=1);

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationDispatch;
use Illuminate\Contracts\Cache\Lock;
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
        fn (RecalibrateTrainingHistoryJob $job): bool => $job->userId === $user->id && $job->delay === 5,
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

it('marks a request dirty before probing the active recalibration lock', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $lock = Mockery::mock(Lock::class);
    Cache::shouldReceive('put')
        ->once()
        ->ordered()
        ->with(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id), true, 3600);
    Cache::shouldReceive('lock')
        ->once()
        ->ordered()
        ->with(RecalibrateTrainingHistoryJob::overlapLockKey($user->id), RecalibrateTrainingHistoryJob::overlapLockTtlSeconds())
        ->andReturn($lock);
    $lock->shouldReceive('get')->once()->andReturnFalse();
    Cache::shouldReceive('get')
        ->once()
        ->ordered()
        ->with(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id))
        ->andReturn(true);

    PlanRecalibrationDispatch::forUserId($user->id);

    Bus::assertNothingDispatched();
    expect(Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id)))->toBeTrue();
});

it('lets another user dispatch while one users recalibration lock is held', function (): void {
    Bus::fake();
    $lockedUser = User::factory()->create();
    $otherUser = User::factory()->create();
    $lock = Cache::lock(
        RecalibrateTrainingHistoryJob::overlapLockKey($lockedUser->id),
        RecalibrateTrainingHistoryJob::overlapLockTtlSeconds(),
    );
    expect($lock->get())->toBeTrue();

    PlanRecalibrationDispatch::forUserId($otherUser->id);

    Bus::assertDispatchedTimes(RecalibrateTrainingHistoryJob::class, 1);
    Bus::assertDispatched(
        RecalibrateTrainingHistoryJob::class,
        fn (RecalibrateTrainingHistoryJob $job): bool => $job->userId === $otherUser->id,
    );
    expect($otherUser->fresh()->plan_recalibration_started_at)->not->toBeNull();

    $lock->release();
});
