<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\AI\Analysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

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
        ->assertJsonPath('props.load.weekly_trimp', fn (mixed $trimp): bool => is_numeric($trimp));
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
