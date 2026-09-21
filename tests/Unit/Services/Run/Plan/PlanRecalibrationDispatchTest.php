<?php

declare(strict_types=1);

use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\User;
use App\Services\Run\Plan\PlanRecalibrationDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

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
});

it('can suppress recursive dispatch while recalibration updates zone-derived data', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    PlanRecalibrationDispatch::withoutDispatching(
        fn () => PlanRecalibrationDispatch::forUserId($user->id),
    );

    Bus::assertNothingDispatched();
});
