<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Actions\Run\Story\ResolveLastRunStartAction;
use App\Services\Run\Metrics\TrainingFormStatus;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Story\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

// Freeze "today" so Carbon::today() math is stable; afterEach prevents leak on failure.
beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('returns hibernating when the user has never run', function (): void {
    $user = User::factory()->create();

    expect(app(Vibe::class)->current($user))->toBe(Vibe::HIBERNATING);
});

it('hibernates from ten days since the last run', function (int $daysAgo, bool $hibernating): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays($daysAgo)->setTime(6, 20),
        'trimp_edwards' => 80.0,
    ]);

    $vibe = app(Vibe::class)->current($user);

    expect($vibe === Vibe::HIBERNATING)->toBe($hibernating);
})->with([
    '12 days ago' => [12, true],
    'today' => [0, false],
    'yesterday' => [1, false],
    '9 days ago, just under the threshold' => [9, false],
    '10 days ago, the threshold boundary' => [10, true],
]);

it('does not hibernate when the analysis as-of date predates the latest run', function (): void {
    // A daily-greeting row freezes its discriminator (as-of date) at creation;
    // a later regeneration can run with an as-of that is *before* the newest run.
    // The signed diff would go negative, so the age must clamp to 0, not flip
    // the runner into hibernating or report a negative age.
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->setTime(6, 20),
        'trimp_edwards' => 80.0,
    ]);

    $asOfBeforeRun = Carbon::today()->subDays(3);

    expect(app(Vibe::class)->current($user, $asOfBeforeRun))->not->toBe(Vibe::HIBERNATING);
});

/** 80 days of steady 50 TRIMP/day → CTL catches ATL, form ≈ -7 (within optimal). */
function userWithOptimalForm(): User
{
    $user = User::factory()->create();
    for ($i = 0; $i < 80; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 50.0,
            'start_date_local' => Carbon::today()->subDays(79 - $i),
        ]);
    }

    return $user;
}

function vibeWithOptimalForm(): Vibe
{
    /** @var TrainingLoad&MockInterface $load */
    $load = Mockery::mock(TrainingLoad::class);
    $load->shouldReceive('summary')->andReturn(['form' => -7.0, 'form_status' => TrainingFormStatus::Optimal]);

    return new Vibe($load, app(ResolveLastRunStartAction::class));
}

/** @param  list<array<string, mixed>>  $summaries */
function userWithRecentRuns(int $count = 1, array $summaries = []): User
{
    $user = User::factory()->create();
    for ($i = 0; $i < $count; $i++) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 50.0,
            'start_date_local' => Carbon::today()->subDays($i),
            'stream_summary' => $summaries[$i] ?? null,
        ]);
    }

    return $user;
}

it('returns pumped on a recent PR with non-negative form', function (): void {
    $user = userWithOptimalForm();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1300.0,
        'set_at' => Carbon::today()->subDays(3),
    ]);

    expect(app(Vibe::class)->current($user))->toBe(Vibe::PUMPED);
});

it('still counts a PR set exactly 14 days ago as recent', function (): void {
    $user = userWithRecentRuns();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1300.0,
        'set_at' => Carbon::today()->subDays(14),
    ]);

    expect(vibeWithOptimalForm()->current($user))->toBe(Vibe::PUMPED);
});

it('no longer counts a PR set 15 days ago as recent', function (): void {
    $user = userWithRecentRuns();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1300.0,
        'set_at' => Carbon::today()->subDays(15),
    ]);

    expect(vibeWithOptimalForm()->current($user))->not->toBe(Vibe::PUMPED);
});

it('averages version 2 decoupling across recent runs to drive the vibe', function (): void {
    $v2 = ['drift_metric_version' => 2, 'steady_effort_decoupling_pct' => -2.0];
    $versionless = ['time_in_zone_min' => ['Z2' => 30]];
    $user = userWithRecentRuns(4, [$v2, $versionless, $v2, $versionless]);

    expect(vibeWithOptimalForm()->current($user))->toBe(Vibe::BOUNCY);
});

it('does not use versionless whole-run values in the vibe', function (): void {
    $user = userWithRecentRuns(4, array_fill(0, 4, ['decoupling_pct' => -2.0]));

    expect(vibeWithOptimalForm()->current($user))->not->toBe(Vibe::BOUNCY);
});

// DashboardController resolves today's vibe in the method body and BriefingComposer
// asks again while rendering. Three statements each, on a scoped() binding.
it('resolves a given day once per request', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDay(),
        'trimp_edwards' => 80.0,
    ]);

    $vibe = app(Vibe::class);
    $vibe->current($user, Carbon::today());

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect($vibe->current($user, Carbon::today()))->toBe($vibe->current($user, Carbon::today()))
        ->and($queries)->toBe(0);
});

it('keeps separate answers per day', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays(11),
        'trimp_edwards' => 80.0,
    ]);

    $vibe = app(Vibe::class);

    expect($vibe->current($user, Carbon::today()))->toBe(Vibe::HIBERNATING)
        ->and($vibe->current($user, Carbon::today()->subDays(9)))->not->toBe(Vibe::HIBERNATING);
});

it('is one shared instance per request', function (): void {
    expect(app(Vibe::class))->toBe(app(Vibe::class));
});
