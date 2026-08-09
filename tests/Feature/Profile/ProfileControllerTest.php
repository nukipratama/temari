<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\StoryLine;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\LifetimeStats;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders Profile with computed identity + hero stats', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'strava_athlete_id' => 12345,
        'scopes' => 'read,activity:read',
    ]);

    $analyzed = Activity::factory()
        ->for($user)
        ->analyzed()
        ->count(2)
        ->create();
    foreach ($analyzed as $idx => $activity) {
        ActivityDetail::factory()->for($activity)->create([
            'distance' => $idx === 0 ? 5000 : 8000, // longest = 8 km
            'start_date_local' => Carbon::today()->subDays(10 - $idx),
        ]);
    }

    // un-analyzed activity should be excluded from the COUNT + SUM
    $unanalyzed = Activity::factory()->for($user)->create(['analyzed_at' => null]);
    ActivityDetail::factory()->for($unanalyzed)->create(['distance' => 99000]);

    $this->actingAs($user)->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('stats.total_runs', 2)
            ->where('stats.total_km', 13)
            ->where('stats.longest_run_km', 8)
            ->where('identity.strava_connected', true));
});

it('reports strava_connected as false when the user has no connection', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('identity.strava_connected', false)
            ->where('stats.total_runs', 0)
            ->where('stats.longest_run_km', 0));
});

it('requires auth', function (): void {
    $this->get('/profile')->assertRedirect('/login');
});

it('includes training_paces derived from VDOT when the user has a qualifying PR', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200.0,
    ]);

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->has('fitness.training_paces.easy')
            ->has('fitness.training_paces.marathon')
            ->has('fitness.training_paces.threshold')
            ->has('fitness.training_paces.interval'));
});

it('reports null training_paces when the user has no VDOT-eligible PR', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page
            ->where('fitness', null));
});

it('exposes personaMix derived from StoryLine moods + the week-keyed profileVoice payload', function (): void {
    Carbon::setTestNow('2026-05-18 09:00:00');

    $user = User::factory()->create();
    $a = Activity::factory()->for($user)->analyzed()->create();
    StoryLine::factory()->for($user)->create([
        'activity_id' => $a->id,
        'mood' => 'nyala',
    ]);

    $this->actingAs($user)->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->missing('personaSummary')
            ->has('personaMix', 1)
            ->where('personaMix.0.mood', 'nyala')
            ->where('personaMix.0.percent', 100)
            ->has('profileVoice')
            ->where('profileVoice.type', 'aku_profile_voice')
            ->where('profileVoice.subject_type', 'aku_profile_voice_user')
            ->where('profileVoice.discriminator', '2026-W21'));

    Carbon::setTestNow();
});

it('resolves the row the weekly kickoff wrote for the current ISO week', function (): void {
    // Monday 2026-05-18 is ISO week 2026-W21, and the row the page must find is
    // the one WeeklyProfileCommand keys with that same week.
    Carbon::setTestNow('2026-05-18 09:00:00');

    $user = User::factory()->create();
    Analysis::factory()->done('Kamu tipe yang sabar ngebangun base.')->create([
        'subject_type' => AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::AkuProfileVoice,
        'discriminator' => '2026-W21',
    ]);
    // Last week's row and the pre-merge unkeyed row must both stay out of the way.
    foreach (['2026-W20', null] as $stale) {
        Analysis::factory()->done('Bacaan lama.')->create([
            'subject_type' => AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE,
            'subject_id' => $user->id,
            'analysis_type' => AnalysisType::AkuProfileVoice,
            'discriminator' => $stale,
        ]);
    }

    $this->actingAs($user)->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profileVoice.status', 'done')
            ->where('profileVoice.content', 'Kamu tipe yang sabar ngebangun base.')
            ->where('profileVoice.discriminator', '2026-W21'));

    Carbon::setTestNow();
});

it('serves the hero stats from LifetimeStats, keeping 1dp total km and 2dp longest run', function (): void {
    $user = User::factory()->create();
    $activities = Activity::factory()->for($user)->analyzed()->count(2)->create();
    // 7,777 m and 10,126 m: total 17.903 km -> 17.9 at 1dp, longest 10.126 km
    // -> 10.13 at 2dp. Both digits differ from the other precision, so a
    // silently swapped rounding would fail here.
    foreach ([7777.0, 10126.0] as $idx => $distance) {
        ActivityDetail::factory()->for($activities[$idx])->create([
            'distance' => $distance,
            'start_date_local' => Carbon::today()->subDays(5 - $idx),
        ]);
    }

    $lifetime = app(LifetimeStats::class)->forUser($user);

    $this->actingAs($user)->get('/profile')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_km', 17.9)
            ->where('stats.longest_run_km', 10.13)
            ->where('stats.total_runs', $lifetime['total_runs'])
            ->where('stats.total_km', $lifetime['total_km'])
            ->where('stats.longest_run_km', $lifetime['longest_km'])
            ->where('identity.first_run_at', $lifetime['first_run_at'])
            ->etc());
});

it('reuses the LifetimeStats cache instead of re-running the aggregate per /aku load', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 9000.0,
        'start_date_local' => Carbon::today()->subDay(),
    ]);

    Cache::forget(LifetimeStats::cacheKey($user->id));

    $this->actingAs($user)->get('/profile')->assertSuccessful();

    $aggregates = [];
    DB::listen(function (QueryExecuted $query) use (&$aggregates): void {
        if (str_contains($query->sql, 'longest_distance')) {
            $aggregates[] = $query->sql;
        }
    });

    $this->actingAs($user)->get('/profile')->assertSuccessful();

    expect($aggregates)->toBeEmpty();
});
