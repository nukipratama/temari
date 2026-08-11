<?php

declare(strict_types=1);

use App\Enums\DistanceBand;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->trainingLoad = app(TrainingLoad::class);
});
afterEach(fn () => Carbon::setTestNow());

function ctxFor(User $user, Season $season, ?TrainingLoad $trainingLoad = null): SeasonGamificationContext
{
    return SeasonGamificationContext::forSeason($user, $season, Carbon::today(), $trainingLoad ?? app(TrainingLoad::class));
}

function seasonFor(User $user, int $daysAgoStart = 5): Season
{
    return Season::factory()->for($user)->create([
        'starts_at' => Carbon::today()->subDays($daysAgoStart)->toDateString(),
        'ends_at' => Carbon::today()->addWeeks(11)->toDateString(),
    ]);
}

it('counts a completed non-rest session as sessions completed', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user);
    $date = Carbon::today()->subDays(2);
    PlannedSession::factory()->for($user)->create(['date' => $date->toDateString(), 'session_type' => SessionType::Easy]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create(['start_date_local' => $date]);

    expect(ctxFor($user, $season)->sessionsCompleted)->toBe(1);
});

it('does not count a non-rest session with no logged activity', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user);
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->subDays(2)->toDateString(), 'session_type' => SessionType::Easy]);

    expect(ctxFor($user, $season)->sessionsCompleted)->toBe(0);
});

it('counts only tempo/interval sessions as quality completed', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user);
    $tempoDate = Carbon::today()->subDays(2);
    $easyDate = Carbon::today()->subDays(3);
    PlannedSession::factory()->for($user)->create(['date' => $tempoDate->toDateString(), 'session_type' => SessionType::Tempo, 'distance_band' => DistanceBand::Medium]);
    PlannedSession::factory()->for($user)->create(['date' => $easyDate->toDateString(), 'session_type' => SessionType::Easy]);
    foreach ([$tempoDate, $easyDate] as $date) {
        ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create(['start_date_local' => $date]);
    }

    expect(ctxFor($user, $season)->qualityCompleted)->toBe(1)
        ->and(ctxFor($user, $season)->sessionsCompleted)->toBe(2);
});

it('reports the longest completed long-band run', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user);
    $shortLong = Carbon::today()->subDays(4);
    $bigLong = Carbon::today()->subDays(2);
    PlannedSession::factory()->for($user)->create(['date' => $shortLong->toDateString(), 'session_type' => SessionType::Long, 'distance_band' => DistanceBand::Long]);
    PlannedSession::factory()->for($user)->create(['date' => $bigLong->toDateString(), 'session_type' => SessionType::Long, 'distance_band' => DistanceBand::Long]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create(['start_date_local' => $shortLong, 'distance' => 10_000]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create(['start_date_local' => $bigLong, 'distance' => 18_000]);

    expect(ctxFor($user, $season)->longestLongRunKm)->toBe(18.0);
});

it('counts a rest day as honored only when no activity was logged', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user);
    $honoredDate = Carbon::today()->subDays(2);
    $brokenDate = Carbon::today()->subDays(3);
    PlannedSession::factory()->for($user)->rest()->create(['date' => $honoredDate->toDateString()]);
    PlannedSession::factory()->for($user)->rest()->create(['date' => $brokenDate->toDateString()]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create(['start_date_local' => $brokenDate]);

    expect(ctxFor($user, $season)->restHonored)->toBe(1);
});

it('does not count a day with no planned session at all as honored', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user);
    // No PlannedSession rows exist for any day in range at all.

    expect(ctxFor($user, $season)->restHonored)->toBe(0);
});

it('only scores past days, never today or the future', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user);
    PlannedSession::factory()->for($user)->rest()->create(['date' => Carbon::today()->toDateString()]);
    PlannedSession::factory()->for($user)->rest()->create(['date' => Carbon::today()->addDay()->toDateString()]);

    expect(ctxFor($user, $season)->restHonored)->toBe(0);
});

it('flags the race goal met when a matching activity finishes within margin', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->subDay()->toDateString(),
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
    ]);
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'starts_at' => Carbon::today()->subDays(5)->toDateString(),
        'ends_at' => $race->race_date->toDateString(),
    ]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create([
        'start_date_local' => $race->race_date->copy()->addHours(8),
        'distance' => 10_100,
        'elapsed_time' => 3_050, // within the 5% margin of 3000
    ]);

    expect(ctxFor($user, $season)->raceGoalMet)->toBeTrue();
});

it('does not flag the race goal met when the finish time misses the margin', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->subDay()->toDateString(),
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
    ]);
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'starts_at' => Carbon::today()->subDays(5)->toDateString(),
        'ends_at' => $race->race_date->toDateString(),
    ]);
    ActivityDetail::factory()->for(Activity::factory()->for($user)->create())->create([
        'start_date_local' => $race->race_date->copy()->addHours(8),
        'distance' => 10_100,
        'elapsed_time' => 4_000, // well outside the margin
    ]);

    expect(ctxFor($user, $season)->raceGoalMet)->toBeFalse();
});

it('never flags the race goal met for a self-scaled season', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user);

    expect(ctxFor($user, $season)->raceGoalMet)->toBeFalse();
});

it('computes CTL growth from the season start to today for a self-scaled season', function (): void {
    $user = User::factory()->create();
    $season = seasonFor($user, daysAgoStart: 30);
    // A steady TRIMP stream builds CTL over the 30 days before the season
    // start plus the 5 days inside it, so CTL at "today" exceeds CTL at
    // the season's start.
    foreach (range(0, 34) as $daysAgo) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'trimp_edwards' => 80,
            'start_date_local' => Carbon::today()->subDays($daysAgo),
        ]);
    }

    expect(ctxFor($user, $season)->ctlGrowth)->toBeGreaterThan(0.0);
});

it('never computes CTL growth for a race-oriented season', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);
    $season = Season::factory()->for($user)->create([
        'race_goal_id' => $race->id,
        'starts_at' => Carbon::today()->subDays(5)->toDateString(),
        'ends_at' => $race->race_date->toDateString(),
    ]);

    expect(ctxFor($user, $season)->ctlGrowth)->toBe(0.0);
});
