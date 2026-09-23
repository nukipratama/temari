<?php

declare(strict_types=1);

use App\Jobs\Run\ReconcilePlanJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Models\AI\Analysis;
use App\Models\PlanAdaptation;
use App\Models\Season;
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

it('requests changed season narration only for recently active athletes', function (): void {
    Bus::fake();
    $active = User::factory()->create();
    $inactive = User::factory()->create(['last_seen_at' => Carbon::today()->subDays(8)]);
    Season::factory()->for($active)->create();
    Season::factory()->for($inactive)->create();
    $active->forceFill(['plan_reconciliation_pending_from' => Carbon::yesterday()])->saveQuietly();
    $inactive->forceFill(['plan_reconciliation_pending_from' => Carbon::yesterday()])->saveQuietly();

    $service = app(PlanReconciliationService::class);
    $service->drain($active->id);
    $service->drain($inactive->id);

    Bus::assertDispatchedTimes(AnalyzePlanSeasonVoiceJob::class, 1);
    Bus::assertDispatched(AnalyzePlanSeasonVoiceJob::class, fn (AnalyzePlanSeasonVoiceJob $job): bool => Analysis::query()->find($job->analysisId)?->subject_id === Season::query()->where('user_id', $active->id)->value('id'));
});

it('does not schedule reconciliation for the demo account', function (): void {
    Bus::fake();
    $user = User::factory()->demo()->create();

    app(PlanReconciliationService::class)->markDirty($user->id, Carbon::yesterday());

    expect($user->fresh()->plan_reconciliation_pending_from)->toBeNull();
    Bus::assertNotDispatched(ReconcilePlanJob::class);
});

it('ignores evidence older than the previous week because it cannot change the current plan', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    app(PlanReconciliationService::class)->markDirty($user->id, Carbon::parse('2026-08-01'));

    expect($user->fresh()->plan_reconciliation_pending_from)->toBeNull();
    Bus::assertNotDispatched(ReconcilePlanJob::class);
});

it('uses the date cursor to classify only the current and previous week as relevant', function (): void {
    $today = Carbon::parse('2026-08-17');

    expect(PlanReconciliationService::dateCanAffectPlan(Carbon::parse('2026-08-10'), $today))->toBeTrue()
        ->and(PlanReconciliationService::dateCanAffectPlan(Carbon::parse('2026-08-09'), $today))->toBeFalse()
        ->and(PlanReconciliationService::dateCanAffectPlan(Carbon::parse('2026-08-18'), $today))->toBeFalse();
});
