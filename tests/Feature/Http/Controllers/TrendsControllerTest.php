<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
