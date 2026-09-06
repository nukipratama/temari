<?php

declare(strict_types=1);

use App\Actions\AI\KickoffMonthlyRecaps;
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

/** A summary-only run in $month (Y-m), the shape a first-connect backfill writes. */
function backfilledRunInMonth(User $user, string $month): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::createFromFormat('Y-m', $month)->startOfMonth()->addDays(10)->setTime(6, 30),
    ]);
}

function stageKickoffMonthlyRecap(User $user, string $month, AnalysisStatus $status): void
{
    Analysis::factory()->create([
        'subject_type' => AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::MonthlyRecap,
        'discriminator' => $month,
        'status' => $status,
    ]);
}

beforeEach(function (): void {
    // Last fully-closed month is 2026-05.
    Carbon::setTestNow('2026-06-17 05:30:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('narrates only the named user when given a user id', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    backfilledRunInMonth($mine, '2026-05');
    backfilledRunInMonth($theirs, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)($mine->id))->toBe(['dispatched' => 1, 'rule_based' => 0])
        ->and(array_column($captured, 'subjectId'))->toBe([$mine->id]);
});

it('sweeps every non-demo user when given no user id', function (): void {
    $one = User::factory()->create();
    $two = User::factory()->create();
    backfilledRunInMonth($one, '2026-05');
    backfilledRunInMonth($two, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    app(KickoffMonthlyRecaps::class)();

    expect(array_column($captured, 'subjectId'))->toContain($one->id, $two->id);
});

it('never dispatches for a demo user even when named directly', function (): void {
    $demo = User::factory()->demo()->create();
    backfilledRunInMonth($demo, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)($demo->id))->toBe(['dispatched' => 0, 'rule_based' => 0])
        ->and($captured)->toBeEmpty();
});

it('fills a month past the backfill depth cap rule-based and narrates the rest, oldest first', function (): void {
    config()->set('ai.backfill_max_age_days', 84);
    config()->set('ai.backfill_stagger_seconds', 100);

    $user = User::factory()->create();
    backfilledRunInMonth($user, '2025-09');
    backfilledRunInMonth($user, '2026-04');
    backfilledRunInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)($user->id))->toBe(['dispatched' => 2, 'rule_based' => 1]);

    expect(collect($captured)->firstWhere('discriminator', '2025-09')['ruleBased'])->toBeTrue()
        ->and(collect($captured)->where('ruleBased', false)->pluck('discriminator')->all())
        ->toBe(['2026-04', '2026-05'])
        ->and(collect($captured)->where('ruleBased', false)->pluck('delaySeconds')->all())
        ->toBe([0, 100]);
});

it('re-dispatches nothing on a second run once every recap is Done', function (): void {
    $user = User::factory()->create();
    backfilledRunInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    app(KickoffMonthlyRecaps::class)($user->id);
    expect(array_column($captured, 'discriminator'))->toBe(['2026-05']);

    stageKickoffMonthlyRecap($user, '2026-05', AnalysisStatus::Done);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)($user->id))->toBe(['dispatched' => 0, 'rule_based' => 0])
        ->and($captured)->toBeEmpty();
});

it('still picks up a Failed recap and skips the open month', function (): void {
    $user = User::factory()->create();
    backfilledRunInMonth($user, '2026-04');
    backfilledRunInMonth($user, '2026-06');
    stageKickoffMonthlyRecap($user, '2026-04', AnalysisStatus::Failed);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    app(KickoffMonthlyRecaps::class)($user->id);

    expect(array_column($captured, 'discriminator'))->toBe(['2026-04']);
});
