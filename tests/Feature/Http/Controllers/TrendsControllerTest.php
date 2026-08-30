<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Models\AI\Analysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\TrendDailySnapshot;
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

it('retired /records and /badges outright, and retargets /rekor to /trends', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/records')->assertNotFound();
    $this->actingAs($user)->get('/badges')->assertNotFound();
    $this->get('/rekor')->assertRedirect('/trends');
});

it('renders an empty fitness trend for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/trends')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Trends')
            ->where('ctlTrend', []));
});

it('renders a fitness trend from the user\'s TRIMP history', function (): void {
    $user = User::factory()->create();
    seedTrendsTrimpDay($user, 80);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('ctlTrend', fn (mixed $trend): bool => count($trend) > 0));
});

it('never surfaces another user\'s training load', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedTrendsTrimpDay($other, 80);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page->where('ctlTrend', []));
});

it('passes a pending narration payload for all three ranges when none exist', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('narration.30d.status', 'pending')
            ->where('narration.90d.status', 'pending')
            ->where('narration.12mo.status', 'pending'));
});

it('passes the TrendRead analysis for each range as its own narration entry', function (): void {
    $user = User::factory()->create();
    Analysis::factory()->done("Fitness is climbing.\n\nCTL moved from 40 to 55.")->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '30d',
    ]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('narration.30d.status', 'done')
            ->where('narration.30d.content', "Fitness is climbing.\n\nCTL moved from 40 to 55.")
            ->where('narration.30d.type', AnalysisType::TrendRead->value)
            ->where('narration.30d.discriminator', '30d')
            ->where('narration.90d.status', 'pending')
            ->where('narration.12mo.status', 'pending'));
});

it('never surfaces another user\'s narration', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    Analysis::factory()->done('Not yours.')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $other->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '30d',
    ]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page->where('narration.30d.status', 'pending'));
});

it('renders an empty load trend for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page->where('loadTrend', []));
});

it('renders a load trend from the user\'s TRIMP history', function (): void {
    $user = User::factory()->create();
    seedTrendsTrimpDay($user, 80);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('loadTrend', fn (mixed $trend): bool => collect($trend)
                ->contains(fn (array $day): bool => $day['strain'] !== null)));
});

it('renders empty VDOT and pace consistency histories for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('vdotHistory', [])
            ->where('vdotSourceCategory', null)
            ->where('paceConsistencyHistory', []));
});

it('renders VDOT and pace consistency histories from the user\'s snapshots', function (): void {
    $user = User::factory()->create();
    TrendDailySnapshot::factory()->for($user)->create([
        'snapshot_date' => now()->toDateString(),
        'vdot' => 42.5,
        'pace_variability_sec' => 9.5,
    ]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('vdotHistory.0.vdot', 42.5)
            ->where('paceConsistencyHistory.0.variabilitySec', 9.5));
});

it('sets vdotSourceCategory from the user\'s limiting personal record', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '10km',
        'value_sec' => 3600,
    ]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page->where('vdotSourceCategory', '10 km'));
});

it('never surfaces another user\'s load or snapshot history', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedTrendsTrimpDay($other, 80);
    TrendDailySnapshot::factory()->for($other)->create();

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('loadTrend', [])
            ->where('vdotHistory', [])
            ->where('paceConsistencyHistory', []));
});

it('renders empty personal bests and badge milestones for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('distanceRecords', [])
            ->where('paceRecords', [])
            ->where('badgeMilestones', []));
});

it('splits personal records into distanceRecords and paceRecords, distance-ascending', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '10km', 'value_sec' => 3000]);
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1200]);
    PersonalRecord::factory()->for($user)->create(['category' => 'best_5min', 'value_sec' => 220]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('distanceRecords.0.category', '5km')
            ->where('distanceRecords.1.category', '10km')
            ->where('paceRecords.0.category', 'best_5min')
            ->where('paceRecords.0.paceSec', 220));
});

it('sets a badge milestone at its first-earned date only', function (): void {
    $user = User::factory()->create();
    $earlier = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($earlier)->create(['start_date_local' => now()->subDays(10)]);
    RunCard::factory()->for($earlier)->create(['badges' => [Badge::EarlyBird->value]]);
    $later = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($later)->create(['start_date_local' => now()->subDay()]);
    RunCard::factory()->for($later)->create(['badges' => [Badge::EarlyBird->value]]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('badgeMilestones', fn (mixed $milestones): bool => count($milestones) === 1
                && $milestones[0]['key'] === Badge::EarlyBird->value));
});

it('never surfaces another user\'s personal bests or badges', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    PersonalRecord::factory()->for($other)->create(['category' => '5km']);
    $activity = Activity::factory()->for($other)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDay()]);
    RunCard::factory()->for($activity)->create(['badges' => [Badge::EarlyBird->value]]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('distanceRecords', [])
            ->where('paceRecords', [])
            ->where('badgeMilestones', []));
});

it('reports a zero streak for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page->where('streak.weeks', 0));
});

it('reports the user\'s consecutive-week streak', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => now()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'runs' => 3,
    ]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page
            ->where('streak.weeks', 1)
            ->where('streak.ran_this_week', true));
});

it('never surfaces another user\'s streak', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    WeeklySnapshot::factory()->for($other)->create([
        'week_ending' => now()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'runs' => 3,
    ]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page->where('streak.weeks', 0));
});
