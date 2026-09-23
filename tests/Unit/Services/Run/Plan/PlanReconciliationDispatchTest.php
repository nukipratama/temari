<?php

declare(strict_types=1);

use App\Jobs\Run\ReconcilePlanJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Plan\PlanReconciliationDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('marks an ingested activity date for plan reconciliation', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-08-17 12:00:00');
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::yesterday()]);

    app(PlanReconciliationDispatch::class)->forActivity($activity->load('detail'));

    expect($activity->user->fresh()->plan_reconciliation_pending_from->toDateString())->toBe('2026-08-16');
    Bus::assertDispatched(ReconcilePlanJob::class);
    Carbon::setTestNow();
});

it('ignores an activity without a local start date', function (): void {
    Bus::fake();
    $activity = Activity::factory()->for(User::factory()->create())->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => null]);

    app(PlanReconciliationDispatch::class)->forActivity($activity->load('detail'));

    expect($activity->user->fresh()->plan_reconciliation_pending_from)->toBeNull();
    Bus::assertNotDispatched(ReconcilePlanJob::class);
});
