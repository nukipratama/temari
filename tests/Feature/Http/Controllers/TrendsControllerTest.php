<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\AI\Analysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function seedTrendsTrimpDay(User $user, float $trimp): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'trimp_edwards' => $trimp,
        'start_date_local' => now()->subDay(),
    ]);
}

it('requires authentication', function (): void {
    $this->get('/trends')->assertRedirect('/login');
});

it('retired /records and /badges outright', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/records')->assertNotFound();
    $this->actingAs($user)->get('/badges')->assertNotFound();
});

it('paints the shell with every heavy block deferred', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/trends')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Trends')
            ->missing('ctlTrend')
            ->missing('narration')
            ->missing('weekComparison')
            ->missing('load')
            ->missing('chartAnnotations')
            ->missing('supportedHistory')
            ->etc());
});

it('ships the week comparison from BriefingContext, through the same weekday', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => now()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'runs' => 3,
        'distance_km' => 18.4,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => now()->endOfWeek(Carbon::SUNDAY)->subWeek()->toDateString(),
        'runs' => 4,
        'distance_km' => 22.1,
    ]);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'weekComparison'))
        ->assertSuccessful()
        ->assertJsonPath('props.weekComparison.this_week_km', 18.4)
        ->assertJsonPath('props.weekComparison.this_week_runs', 3);
});

it('ships app-local Y-m-d ranges for the calendar and rolling load windows', function (
    string $today,
    array $dateRanges,
): void {
    Carbon::setTestNow(Carbon::parse($today.' 12:00:00', 'Asia/Jakarta'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'weekComparison'))
        ->assertSuccessful()
        ->assertJsonPath('props.weekComparison.date_ranges', $dateRanges)
        ->assertJsonPath('props.weekComparison.this_week_km', null)
        ->assertJsonPath('props.weekComparison.last_week_km', null);
})->with([
    'partial Monday week with empty history' => ['2026-09-28', [
        'this_week' => ['start' => '2026-09-28', 'end' => '2026-09-28'],
        'last_week' => ['start' => '2026-09-21', 'end' => '2026-09-21'],
        'load' => ['start' => '2026-09-22', 'end' => '2026-09-28'],
    ]],
    'complete Sunday week' => ['2026-10-04', [
        'this_week' => ['start' => '2026-09-28', 'end' => '2026-10-04'],
        'last_week' => ['start' => '2026-09-21', 'end' => '2026-09-27'],
        'load' => ['start' => '2026-09-28', 'end' => '2026-10-04'],
    ]],
    'month and year rollover' => ['2027-01-01', [
        'this_week' => ['start' => '2026-12-28', 'end' => '2027-01-01'],
        'last_week' => ['start' => '2026-12-21', 'end' => '2026-12-25'],
        'load' => ['start' => '2026-12-26', 'end' => '2027-01-01'],
    ]],
]);

it('never surfaces another user\'s week comparison', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    WeeklySnapshot::factory()->for($other)->create([
        'week_ending' => now()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'runs' => 9,
        'distance_km' => 99.0,
    ]);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'weekComparison'))
        ->assertJsonPath('props.weekComparison.this_week_km', null)
        ->assertJsonPath('props.weekComparison.this_week_runs', null);
});

it('ships the load section as one 7-day summary, not one entry per range', function (): void {
    $user = User::factory()->create();
    seedTrendsTrimpDay($user, 80);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'load'))
        ->assertSuccessful()
        ->assertJsonPath('props.load.ctl_42d', fn (mixed $ctl): bool => is_numeric($ctl))
        ->assertJsonPath('props.load.weekly_trimp', fn (mixed $trimp): bool => is_numeric($trimp))
        ->assertJsonMissingPath('props.load.weekly_trimp_reference');
});

it('never surfaces another user\'s training load in the load summary', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedTrendsTrimpDay($other, 80);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'load'))
        ->assertJsonPath('props.load', null);
});

it('renders an empty fitness trend for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'ctlTrend'))
        ->assertSuccessful()
        ->assertJsonPath('component', 'Trends')
        ->assertJsonPath('props.ctlTrend', []);
});

it('renders a fitness trend from the user\'s TRIMP history', function (): void {
    $user = User::factory()->create();
    seedTrendsTrimpDay($user, 80);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'ctlTrend'))
        ->assertJsonPath('props.ctlTrend', fn (mixed $trend): bool => is_array($trend) && count($trend) > 0);
});

it('never surfaces another user\'s training load on the fitness trend', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedTrendsTrimpDay($other, 80);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'ctlTrend'))
        ->assertJsonPath('props.ctlTrend', []);
});

it('passes a pending narration payload for the 7d verdict when none exists', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'narration'))
        ->assertJsonPath('props.narration.status', 'pending')
        ->assertJsonPath('props.narration.discriminator', '7d');
});

it('passes the TrendRead 7d analysis as the single narration payload', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done("Holding steady this week.\n\nNo real swing either way.")->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '7d',
    ]);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'narration'))
        ->assertJsonPath('props.narration.status', 'done')
        ->assertJsonPath('props.narration.content', "Holding steady this week.\n\nNo real swing either way.")
        ->assertJsonPath('props.narration.type', AnalysisType::TrendRead->value)
        ->assertJsonPath('props.narration.discriminator', '7d');
});

it('ignores a stored 30d row when reading the verdict, since only 7d is live', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done('an old 30d read')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '30d',
    ]);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'narration'))
        ->assertJsonPath('props.narration.status', 'pending')
        ->assertJsonPath('props.narration.discriminator', '7d');
});

it('never surfaces another user\'s narration', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Analysis::factory()->done('Not yours.')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $other->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '7d',
    ]);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'narration'))
        ->assertJsonPath('props.narration.status', 'pending');
});

it('renders empty chart annotations for a user with no deload weeks or races', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'chartAnnotations'))
        ->assertSuccessful()
        ->assertJsonPath('props.chartAnnotations.deload', [])
        ->assertJsonPath('props.chartAnnotations.race', []);
});

it('marks a deload week and a race day from the athlete\'s plan history', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => now()->subDays(10)->toDateString(),
        'phase' => PlanPhase::Deload,
        'session_type' => SessionType::Easy,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => now()->subDays(3)->toDateString(),
        'phase' => PlanPhase::Peak,
        'session_type' => SessionType::Race,
    ]);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'chartAnnotations'))
        ->assertJsonPath('props.chartAnnotations.deload', [now()->subDays(10)->toDateString()])
        ->assertJsonPath('props.chartAnnotations.race', [now()->subDays(3)->toDateString()]);
});

it('never surfaces another user\'s plan annotations', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    PlannedSession::factory()->for($other)->create([
        'date' => now()->subDays(10)->toDateString(),
        'phase' => PlanPhase::Deload,
    ]);

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'chartAnnotations'))
        ->assertJsonPath('props.chartAnnotations.deload', []);
});

it('serves the active race outlook from the same presenter as /race, and null with no race', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'raceOutlook'))
        ->assertJsonPath('props.raceOutlook', null);

    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(10)->toDateString(), 'distance_m' => 10_000, 'goal_time_sec' => 3_000]);

    $outlook = $this->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($this->actingAs($user), '/trends', 'Trends', 'raceOutlook'))
        ->json('props.raceOutlook');
    $race = $this->actingAs($user)->get('/race')->assertInertia(fn ($page) => $page->has('race.ambition'));

    expect($outlook['ambition']['state'])->toBe('unknown')
        ->and($outlook['support']['dedicated_preparation'])->toBeTrue()
        ->and($outlook['ambition'])->toBe($race->viewData('page')['props']['race']['ambition']);
});

function supportedSnapshot(User $user, ?RaceGoal $race, string $date, ?int $supportedSec, ?int $sourceM = 5000, ?string $sourceOn = '2026-08-01'): void
{
    TrendDailySnapshot::factory()->for($user)->create([
        'snapshot_date' => $date,
        'race_goal_id' => $race?->id,
        'supported_time_sec' => $supportedSec,
        'supported_source_distance_m' => $supportedSec === null ? null : $sourceM,
        'supported_source_date' => $supportedSec === null ? null : $sourceOn,
    ]);
}

function supportedHistoryProp(mixed $test, User $user): mixed
{
    return $test->actingAs($user)
        ->get('/trends', inertiaPartialHeaders($test->actingAs($user), '/trends', 'Trends', 'supportedHistory'))
        ->json('props.supportedHistory');
}

it('serves the current race\'s supported time over its season, labelling a step only when the source effort changed', function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['goal_time_sec' => 3_000]);
    Season::factory()->for($user)->create(['race_goal_id' => $race->id, 'starts_at' => '2026-08-03']);
    supportedSnapshot($user, $race, '2026-08-02', 3_400);
    supportedSnapshot($user, $race, '2026-08-03', 3_300);
    supportedSnapshot($user, $race, '2026-08-04', 3_250);
    supportedSnapshot($user, $race, '2026-08-05', 3_200, 10_000, '2026-08-05');
    supportedSnapshot($user, $race, '2026-08-06', 3_200, 10_000, '2026-08-05');
    supportedSnapshot($user, $race, '2026-08-07', null);

    $history = supportedHistoryProp($this, $user);

    expect($history['target_time_sec'])->toBe(3_000)
        ->and(array_column($history['points'], 'date'))->toBe(['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'])
        ->and(array_column($history['points'], 'supported_time_sec'))->toBe([3_300, 3_250, 3_200, 3_200])
        ->and(array_column($history['points'], 'new_source'))->toBe([false, false, true, false])
        ->and($history['points'][2]['source'])->toBe(['distance_m' => 10_000, 'date' => '2026-08-05']);
});

it('plots only snapshots taken for the current race goal', function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $user = User::factory()->create();
    $old = RaceGoal::factory()->for($user)->completed()->create();
    $race = RaceGoal::factory()->for($user)->create();
    supportedSnapshot($user, $old, '2026-09-01', 3_500);
    supportedSnapshot($user, $old, '2026-09-02', 3_450);
    supportedSnapshot($user, $race, '2026-10-03', 3_300);
    supportedSnapshot($user, $race, '2026-10-04', 3_290);

    $history = supportedHistoryProp($this, $user);

    expect(array_column($history['points'], 'date'))->toBe(['2026-10-03', '2026-10-04']);
});

it('serves no supported history without an active race', function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $user = User::factory()->create();
    $old = RaceGoal::factory()->for($user)->completed()->create();
    supportedSnapshot($user, $old, '2026-10-03', 3_300);
    supportedSnapshot($user, $old, '2026-10-04', 3_290);

    expect(supportedHistoryProp($this, $user))->toBeNull();
});

it('serves no supported history with fewer than two days of it', function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create();
    supportedSnapshot($user, $race, '2026-10-04', 3_290);
    supportedSnapshot($user, $race, '2026-10-05', null);

    expect(supportedHistoryProp($this, $user))->toBeNull();
});

it('serves no supported history when the race has no supported time', function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['distance_m' => 50_000, 'goal_time_sec' => 18_000]);
    supportedSnapshot($user, null, '2026-10-04', null);
    supportedSnapshot($user, null, '2026-10-05', null);

    expect(supportedHistoryProp($this, $user))->toBeNull();
});

it('never surfaces another user\'s supported history', function (): void {
    Carbon::setTestNow('2026-10-05 09:00:00');
    $user = User::factory()->create();
    $other = User::factory()->create();
    $race = RaceGoal::factory()->for($other)->create();
    supportedSnapshot($other, $race, '2026-10-03', 3_300);
    supportedSnapshot($other, $race, '2026-10-04', 3_290);

    expect(supportedHistoryProp($this, $user))->toBeNull();
});
