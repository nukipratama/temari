<?php

declare(strict_types=1);

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StravaConnection;
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

/** A genuinely summary-only (not yet detail-hydrated) run in $month (Y-m). */
function unhydratedRunInMonth(User $user, string $month): void
{
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
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

it('skips an athlete away from the app, even when named directly', function (): void {
    $away = User::factory()->create(['last_seen_at' => Carbon::today()->subDays(8)]);
    backfilledRunInMonth($away, '2026-05');
    backfilledRunInMonth($away, '2025-01');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)())->toBe(['dispatched' => 0, 'rule_based' => 0])
        ->and(app(KickoffMonthlyRecaps::class)($away->id))->toBe(['dispatched' => 0, 'rule_based' => 0])
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

it('fills a month that closed before the athlete connected rule-based, and narrates one that closed after', function (): void {
    $user = User::factory()->create();
    // Connected mid-May: April already closed, May had not.
    StravaConnection::factory()->for($user)->create(['created_at' => '2026-05-20 08:00:00']);

    backfilledRunInMonth($user, '2026-04');
    backfilledRunInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)($user->id))->toBe(['dispatched' => 1, 'rule_based' => 1]);

    expect(collect($captured)->firstWhere('discriminator', '2026-04')['ruleBased'])->toBeTrue()
        ->and(collect($captured)->firstWhere('discriminator', '2026-05'))
        ->toMatchArray(['ruleBased' => false, 'invalidate' => false, 'delaySeconds' => 0]);
});

it('defers a narratable month whose own runs are still hydrating (#1054)', function (): void {
    // Connected the evening before the month closed (post-connect, so the
    // month is narratable in principle) and still within the 48h hydration
    // grace as of "now".
    Carbon::setTestNow('2026-06-01 10:00:00');
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => '2026-05-31 20:00:00']);
    unhydratedRunInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)($user->id))->toBe(['dispatched' => 0, 'rule_based' => 0]);

    expect(collect($captured)->firstWhere('discriminator', '2026-05'))->not->toBeNull();

    Carbon::setTestNow();
});

it('defers a hydrating month past the grace window too if a run there truly never hydrates (#1054)', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => '2020-01-01 00:00:00']);
    unhydratedRunInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    // Long past the 48h hydration grace: the month is no longer deferred and
    // narrates from whatever has landed, rather than sitting Pending forever.
    expect(app(KickoffMonthlyRecaps::class)($user->id))->toBe(['dispatched' => 1, 'rule_based' => 0]);
});

it('narrates every completed month for an athlete who connected long ago', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => '2025-01-01 00:00:00']);
    backfilledRunInMonth($user, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)($user->id))->toBe(['dispatched' => 1, 'rule_based' => 0])
        ->and(array_column($captured, 'discriminator'))->toBe(['2026-05']);
});

it('never dispatches for the demo account regardless of connect date', function (): void {
    $demo = User::factory()->demo()->create();
    StravaConnection::factory()->for($demo)->create(['created_at' => Carbon::today()->subDay()]);
    backfilledRunInMonth($demo, '2026-05');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffMonthlyRecaps::class)($demo->id))->toBe(['dispatched' => 0, 'rule_based' => 0])
        ->and($captured)->toBeEmpty();
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
