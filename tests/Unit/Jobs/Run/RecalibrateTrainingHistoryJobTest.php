<?php

declare(strict_types=1);

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('uses the user id as its unique queue key', function (): void {
    expect(new RecalibrateTrainingHistoryJob(42)->uniqueId())->toBe('42');
});

it('uses a per-user overlap lock that outlives its queue timeout', function (): void {
    $job = new RecalibrateTrainingHistoryJob(42);
    $middleware = $job->middleware()[0];

    expect($middleware->getLockKey($job))->toBe('laravel-queue-overlap:training-recalibration:42')
        ->and($middleware->releaseAfter)->toBe(1)
        ->and($middleware->expiresAfter)->toBeGreaterThanOrEqual($job->timeout);
});

it('ignores a demo user', function (): void {
    $demo = User::factory()->demo()->create();

    new RecalibrateTrainingHistoryJob($demo->id)->handle(app(PlanRecalibrationService::class));

    expect($demo->fresh()->plan_recalibration_started_at)->toBeNull();
});

it('dispatches one follow-up after a run that was marked dirty', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    RecalibrateTrainingHistoryJob::markDirty($user->id);
    RecalibrateTrainingHistoryJob::markDirty($user->id);

    new RecalibrateTrainingHistoryJob($user->id)->handle(app(PlanRecalibrationService::class));

    Bus::assertDispatchedTimes(RecalibrateTrainingHistoryJob::class, 1);
    expect(Cache::has(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id)))->toBeFalse();
});

it('does not dispatch a follow-up after a clean run', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    new RecalibrateTrainingHistoryJob($user->id)->handle(app(PlanRecalibrationService::class));

    Bus::assertNothingDispatched();
});
