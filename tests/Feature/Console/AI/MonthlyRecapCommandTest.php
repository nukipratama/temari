<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** A run in $month (Y-m) for the given user. */
function runInMonth(User $user, string $month): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::createFromFormat('Y-m', $month)->startOfMonth()->addDays(10)->setTime(6, 30),
    ]);
}

/** Stage a MonthlyRecap Analysis row at a given status for a user/month. */
function stageMonthlyRecap(User $user, string $month, AnalysisStatus $status): void
{
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => $month,
        'status' => $status,
    ]);
}

it('narrates every completed month not yet Done, oldest first, with staggered delays and invalidate:false', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00'); // last closed month = 2026-05
    config()->set('ai.backfill_stagger_seconds', 100);

    $user = User::factory()->create();
    runInMonth($user, '2026-03');
    runInMonth($user, '2026-04');
    runInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:monthly-recap')
        ->expectsOutputToContain('Dispatched monthly recap for 3 months (0 filled rule-based) through 2026-05.')
        ->assertSuccessful();

    expect($captured)->toHaveCount(3)
        ->and(array_column($captured, 'discriminator'))->toBe(['2026-03', '2026-04', '2026-05'])
        ->and(array_column($captured, 'delaySeconds'))->toBe([0, 100, 200])
        ->and(collect($captured)->pluck('invalidate')->every(fn (bool $i): bool => $i === false))->toBeTrue()
        ->and($captured[0]['type'])->toBe(AnalysisType::MonthlyRecap)
        ->and($captured[0]['subjectOrType'])->toBe(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
        ->and($captured[0]['subjectId'])->toBe($user->id);

    Carbon::setTestNow();
});

it('excludes the demo user (monthly is real-users-only)', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');

    $real = User::factory()->create();
    runInMonth($real, '2026-05');
    $demo = User::factory()->demo()->create();
    runInMonth($demo, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:monthly-recap')
        ->expectsOutputToContain('Dispatched monthly recap for 1 months (0 filled rule-based) through 2026-05.')
        ->assertSuccessful();

    expect(array_column($captured, 'subjectId'))->toBe([$real->id]);

    Carbon::setTestNow();
});

it('skips months whose recap is already Done and the open (current) month', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');

    $user = User::factory()->create();
    // Already Done → must NOT be re-dispatched (no re-bill).
    runInMonth($user, '2026-04');
    stageMonthlyRecap($user, '2026-04', AnalysisStatus::Done);
    // Pending → narrate.
    runInMonth($user, '2026-05');
    stageMonthlyRecap($user, '2026-05', AnalysisStatus::Pending);
    // Current in-progress month → not completed yet, skip.
    runInMonth($user, '2026-06');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:monthly-recap')->assertSuccessful();

    expect(array_column($captured, 'discriminator'))->toBe(['2026-05']);

    Carbon::setTestNow();
});

it('narrates a Failed and a far-back historical month (resume safety net)', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');

    $user = User::factory()->create();
    // Far-back month whose recap Failed → still picked up.
    runInMonth($user, '2026-01');
    stageMonthlyRecap($user, '2026-01', AnalysisStatus::Failed);
    runInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:monthly-recap')->assertSuccessful();

    expect(array_column($captured, 'discriminator'))->toBe(['2026-01', '2026-05']);

    Carbon::setTestNow();
});

it('fills a month older than the backfill depth cap rule-based instead of a real LLM dispatch', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00'); // last closed month = 2026-05
    config()->set('ai.backfill_max_age_days', 365);

    $user = User::factory()->create();
    runInMonth($user, '2025-01'); // well over 365 days before 2026-06-17
    runInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:monthly-recap')
        ->expectsOutputToContain('Dispatched monthly recap for 1 months (1 filled rule-based) through 2026-05.')
        ->assertSuccessful();

    $ruleBased = collect($captured)->firstWhere('discriminator', '2025-01');
    $real = collect($captured)->firstWhere('discriminator', '2026-05');

    expect($ruleBased['ruleBased'])->toBeTrue()
        ->and($real['ruleBased'])->toBeFalse();

    Carbon::setTestNow();
});

it('dispatches nothing when nobody ran a completed month', function (): void {
    Carbon::setTestNow('2026-06-17 05:30:00');

    $user = User::factory()->create();
    // Only a current-month run → not yet completed.
    runInMonth($user, '2026-06');

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:monthly-recap')
        ->expectsOutputToContain('Dispatched monthly recap for 0 months (0 filled rule-based) through 2026-05.')
        ->assertSuccessful();

    Carbon::setTestNow();
});
