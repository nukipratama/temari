<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Models\AI\Analysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
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

it('renders empty badge milestones for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page->where('badgeMilestones', []));
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

it('never surfaces another user\'s badges', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => now()->subDay()]);
    RunCard::factory()->for($activity)->create(['badges' => [Badge::EarlyBird->value]]);

    $this->actingAs($user)->get('/trends')
        ->assertInertia(fn (Assert $page) => $page->where('badgeMilestones', []));
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
