<?php

declare(strict_types=1);

use App\Actions\AI\KickoffWeeklyRecaps;
use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Monday 2026-05-18; last completed week ends Sunday 2026-05-17.
    Carbon::setTestNow('2026-05-18 05:30:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('narrates only the named user when given a user id', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $myWeek = WeeklySnapshot::factory()->for($mine)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    $theirWeek = WeeklySnapshot::factory()->for($theirs)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $result = app(KickoffWeeklyRecaps::class)($mine->id);

    expect($result)->toBe(['dispatched' => 1, 'rule_based' => 0, 'deferred' => 0])
        ->and(array_column($captured, 'subjectId'))
        ->toBe([$myWeek->id])
        ->not->toContain($theirWeek->id);
});

it('sweeps every non-demo user when given no user id', function (): void {
    $one = WeeklySnapshot::factory()->for(User::factory())->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    $two = WeeklySnapshot::factory()->for(User::factory())->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    app(KickoffWeeklyRecaps::class)();

    expect(array_column($captured, 'subjectId'))->toContain($one->id, $two->id);
});

it('never dispatches for a demo user even when named directly', function (): void {
    $demo = User::factory()->demo()->create();
    WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffWeeklyRecaps::class)($demo->id))->toBe(['dispatched' => 0, 'rule_based' => 0, 'deferred' => 0])
        ->and($captured)->toBeEmpty();
});

it('fills a week past the backfill depth cap rule-based and narrates the rest', function (): void {
    config()->set('ai.backfill_max_age_days', 84);
    config()->set('ai.backfill_stagger_seconds', 100);

    $user = User::factory()->create();
    $tooOld = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2025-11-30', 'runs' => 2]);
    $recent = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffWeeklyRecaps::class)($user->id))->toBe(['dispatched' => 1, 'rule_based' => 1, 'deferred' => 0]);

    expect(collect($captured)->firstWhere('subjectId', $tooOld->id)['ruleBased'])->toBeTrue()
        ->and(collect($captured)->firstWhere('subjectId', $recent->id))
        ->toMatchArray(['ruleBased' => false, 'invalidate' => false, 'delaySeconds' => 0]);
});

it('re-dispatches nothing on a second run once every recap is Done', function (): void {
    $user = User::factory()->create();
    $week = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    app(KickoffWeeklyRecaps::class)($user->id);
    expect(array_column($captured, 'subjectId'))->toBe([$week->id]);

    // What the first pass' narration would leave behind.
    Analysis::factory()->done('Solid week.')->create([
        'subject_type' => WeeklySnapshot::class,
        'subject_id' => $week->id,
        'analysis_type' => AnalysisType::WeeklyRecap,
    ]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffWeeklyRecaps::class)($user->id))->toBe(['dispatched' => 0, 'rule_based' => 0, 'deferred' => 0])
        ->and($captured)->toBeEmpty();
});

it('still picks up a Pending or Failed recap on a second run', function (): void {
    $user = User::factory()->create();
    $pending = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-10', 'runs' => 3]);
    $failed = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);

    foreach ([[$pending, AnalysisStatus::Pending], [$failed, AnalysisStatus::Failed]] as [$snapshot, $status]) {
        Analysis::factory()->create([
            'subject_type' => WeeklySnapshot::class,
            'subject_id' => $snapshot->id,
            'analysis_type' => AnalysisType::WeeklyRecap,
            'status' => $status,
        ]);
    }

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    app(KickoffWeeklyRecaps::class)($user->id);

    expect(array_column($captured, 'subjectId'))->toBe([$pending->id, $failed->id]);
});

it('skips the open week and weeks with no runs', function (): void {
    $user = User::factory()->create();
    $completed = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-24', 'runs' => 2]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-03', 'runs' => 0]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    app(KickoffWeeklyRecaps::class)($user->id);

    expect(array_column($captured, 'subjectId'))->toBe([$completed->id]);
});

/**
 * A run in the week ending 2026-05-17 that `strava:hydrate-backlog` still owes
 * a detail fetch — the state a recap must not narrate against.
 */
function unhydratedRunInLastWeek(User $user): Activity
{
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-05-14')]);

    return $activity;
}

it('defers a week the ingest pipeline is still hydrating', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    unhydratedRunInLastWeek($user);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffWeeklyRecaps::class)($user->id))->toBe(['dispatched' => 0, 'rule_based' => 0, 'deferred' => 1])
        ->and($captured)->toBeEmpty()
        ->and(Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->count())->toBe(0);
});

it('dispatches the same week on the next run once hydration has finished', function (): void {
    $user = User::factory()->create();
    $week = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    $activity = unhydratedRunInLastWeek($user);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));
    app(KickoffWeeklyRecaps::class)($user->id);
    expect($captured)->toBeEmpty();

    $activity->update(['ingest_state' => IngestState::Detailed]);

    expect(app(KickoffWeeklyRecaps::class)($user->id))->toBe(['dispatched' => 1, 'rule_based' => 0, 'deferred' => 0])
        ->and(array_column($captured, 'subjectId'))->toBe([$week->id]);
});

it('narrates a still-unhydrated week once its grace window has run out', function (): void {
    $user = User::factory()->create();
    $week = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    unhydratedRunInLastWeek($user);

    Carbon::setTestNow('2026-05-20 00:01:00');

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffWeeklyRecaps::class)($user->id))->toBe(['dispatched' => 1, 'rule_based' => 0, 'deferred' => 0])
        ->and(array_column($captured, 'subjectId'))->toBe([$week->id]);
});

it('leaves an athlete with no hydration backlog untouched', function (): void {
    $user = User::factory()->create();
    $week = WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::parse('2026-05-14')]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffWeeklyRecaps::class)($user->id))->toBe(['dispatched' => 1, 'rule_based' => 0, 'deferred' => 0])
        ->and(array_column($captured, 'subjectId'))->toBe([$week->id]);
});

it('never defers or dispatches for the demo account, backlog or not', function (): void {
    $demo = User::factory()->demo()->create();
    WeeklySnapshot::factory()->for($demo)->create(['week_ending' => '2026-05-17', 'runs' => 4]);
    unhydratedRunInLastWeek($demo);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    expect(app(KickoffWeeklyRecaps::class)())->toBe(['dispatched' => 0, 'rule_based' => 0, 'deferred' => 0])
        ->and($captured)->toBeEmpty();
});
