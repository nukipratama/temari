<?php

declare(strict_types=1);

use App\Jobs\Run\ReconcilePlanJob;
use App\Models\PlanAdaptation;
use App\Models\User;
use App\Services\Run\Plan\PlanReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('keeps the earliest pending evidence date when several material changes arrive', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $service = app(PlanReconciliationService::class);

    $service->markDirty($user->id, Carbon::parse('2026-08-15'));
    $service->markDirty($user->id, Carbon::parse('2026-08-16'));
    $service->markDirty($user->id, Carbon::parse('2026-08-14'));

    expect($user->fresh()->plan_reconciliation_pending_from->toDateString())->toBe('2026-08-14');
    Bus::assertDispatched(ReconcilePlanJob::class);
});

it('reconciles once and clears its rebuilding marker after the plan settles', function (): void {
    $user = User::factory()->create([
        'plan_reconciliation_pending_from' => Carbon::parse('2026-08-15'),
    ]);

    app(PlanReconciliationService::class)->drain($user->id);

    expect($user->fresh()->plan_reconciliation_pending_from)->toBeNull()
        ->and($user->fresh()->plan_reconciliation_rebuilding_from)->toBeNull()
        ->and(PlanAdaptation::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('does not schedule reconciliation for the demo account', function (): void {
    Bus::fake();
    $user = User::factory()->demo()->create();

    app(PlanReconciliationService::class)->markDirty($user->id, Carbon::yesterday());

    expect($user->fresh()->plan_reconciliation_pending_from)->toBeNull();
    Bus::assertNotDispatched(ReconcilePlanJob::class);
});
