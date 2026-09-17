<?php

declare(strict_types=1);

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\SeasonGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\SeasonService;
use App\Services\Run\Plan\SeasonSummaryBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->service = app(SeasonService::class);
});
afterEach(fn () => Carbon::setTestNow());

it('creates a self-scaled 12-week season for a user with no active race', function (): void {
    $user = User::factory()->create();

    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect($season->race_goal_id)->toBeNull()
        ->and($season->starts_at->toDateString())->toBe('2026-08-10')
        ->and($season->ends_at->toDateString())->toBe(Carbon::parse('2026-08-10')->addWeeks(12)->toDateString());
});

it('creates a race-oriented season ending on race day when an active race exists', function (): void {
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);

    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect($season->race_goal_id)->toBe($race->id)
        ->and($season->ends_at->toDateString())->toBe($race->race_date->toDateString());
});

it('generates exactly 5 season goals', function (): void {
    $user = User::factory()->create();

    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect(SeasonGoal::query()->where('season_id', $season->id)->count())->toBe(5);
});

it('generates a race-margin goal for a race-oriented season and a CTL-growth goal for self-scaled', function (): void {
    $user = User::factory()->create();
    $season = $this->service->ensureCurrent($user, Carbon::today());
    $metrics = SeasonGoal::query()->where('season_id', $season->id)->pluck('metric')->all();
    expect($metrics)->toContain('season_ctl_growth')->not->toContain('season_race_goal_met');

    $userWithRace = User::factory()->create();
    RaceGoal::factory()->for($userWithRace)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);
    $raceSeason = $this->service->ensureCurrent($userWithRace, Carbon::today());
    $raceMetrics = SeasonGoal::query()->where('season_id', $raceSeason->id)->pluck('metric')->all();
    expect($raceMetrics)->toContain('season_race_goal_met')->not->toContain('season_ctl_growth');
});

it('returns the same season on a second call the same day, without duplicating goals', function (): void {
    $user = User::factory()->create();

    $first = $this->service->ensureCurrent($user, Carbon::today());
    $second = $this->service->ensureCurrent($user, Carbon::today());

    expect($second->id)->toBe($first->id)
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SeasonGoal::query()->where('season_id', $first->id)->count())->toBe(5);
});

it('ends a self-scaled season early and starts a race-oriented one when a race is set mid-season', function (): void {
    $user = User::factory()->create();
    $selfScaled = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow('2026-08-17 08:00:00'); // a week later
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);

    $raceOriented = $this->service->ensureCurrent($user, Carbon::today());

    expect($raceOriented->id)->not->toBe($selfScaled->id)
        ->and($raceOriented->race_goal_id)->not->toBeNull()
        ->and($selfScaled->fresh()->ends_at->toDateString())->toBe('2026-08-16')
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('retargets the season in place, rather than opening a duplicate row, when the race is set the same day the season started', function (): void {
    $user = User::factory()->create();
    $selfScaled = $this->service->ensureCurrent($user, Carbon::today());
    $race = RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(3)->toDateString()]);

    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect($season->id)->toBe($selfScaled->id)
        ->and($season->race_goal_id)->toBe($race->id)
        ->and($season->ends_at->toDateString())->toBe($race->race_date->toDateString())
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(SeasonGoal::query()->where('season_id', $season->id)->pluck('metric')->all())->toEqualCanonicalizing([
            'season_sessions_completed',
            'season_quality_completed',
            'season_longest_long_run_km',
            'season_rest_honored',
            'season_race_goal_met',
            'season_peak_weekly_km',
        ]);
});

it('starts a new self-scaled season when the active race is cleared mid-season', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(9)->toDateString()]);
    $raceOriented = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow('2026-08-17 08:00:00');
    // What RaceController::destroy() does: a mass update fires no model events,
    // so both the shared-prop cache and the per-request memo are dropped by hand.
    RaceGoal::query()->where('user_id', $user->id)->update(['completed_at' => now()]);
    app(ResolveActiveRaceAction::class)->forget($user->id);

    $selfScaled = $this->service->ensureCurrent($user, Carbon::today());

    expect($selfScaled->id)->not->toBe($raceOriented->id)
        ->and($selfScaled->race_goal_id)->toBeNull();
});

it('auto-cycles a self-scaled season into a fresh one once it expires, without overlapping the old one', function (): void {
    $user = User::factory()->create();
    $first = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow($first->ends_at->copy()->addDay()->format('Y-m-d H:i:s'));
    $second = $this->service->ensureCurrent($user, Carbon::today());

    expect($second->id)->not->toBe($first->id)
        ->and($second->starts_at->toDateString())->toBe(Carbon::today()->toDateString())
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(2)
        // No gap and no overlap: the new season starts exactly where the old one ended.
        ->and($first->fresh()->ends_at->toDateString())->toBe(Carbon::today()->copy()->subDay()->toDateString());
});

it('peeks null when the user has no season yet, without creating one', function (): void {
    $user = User::factory()->create();

    expect($this->service->peekCurrent($user, Carbon::today()))->toBeNull()
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('peeks the same season ensureCurrent already created, without mutating it', function (): void {
    $user = User::factory()->create();
    $created = $this->service->ensureCurrent($user, Carbon::today());

    $peeked = $this->service->peekCurrent($user, Carbon::today());

    expect($peeked)->not->toBeNull()
        ->and($peeked->id)->toBe($created->id)
        ->and(Season::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('peeks null once a self-scaled season has expired, rather than treating it as still current', function (): void {
    $user = User::factory()->create();
    $season = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow($season->ends_at->copy()->addDay()->format('Y-m-d H:i:s'));

    expect($this->service->peekCurrent($user, Carbon::today()))->toBeNull();
});

it('scales the quality-session target with the athlete\'s own trailing session count', function (): void {
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 6,
            'distance_km' => 50.0,
        ]);
    }

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $qualityGoal = SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_quality_completed')->first();

    // A 6-runs/week athlete gets 2 quality slots/week across a 12-week build/deload
    // cycle (3 build weeks at 2 slots + 1 deload week at 0, repeated 3x) = 18.
    expect($qualityGoal->target)->toBe(18.0);
});

it('respects an explicit sessions_per_week preference below the old behavioral floor of 3', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 2, 'run_days' => null, 'long_run_day' => null]);

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $sessionsGoal = SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_sessions_completed')->first();

    expect($sessionsGoal->target)->toBe(24.0); // 2 sessions/week * 12-week self-scaled horizon
});

function seasonServiceWeeks(User $user, float $km): void
{
    foreach (range(0, 5) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 4,
            'distance_km' => $km,
        ]);
    }
}

it('opens a race season far from its block with the general goals only', function (int $distanceM, float $weeklyKm, float $readinessKm): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, $weeklyKm);
    RaceGoal::factory()->for($user)->create(['race_date' => '2027-03-08', 'distance_m' => $distanceM]);

    $season = $this->service->ensureCurrent($user, Carbon::today());
    $goals = SeasonGoal::query()->where('season_id', $season->id)->pluck('target', 'metric')->all();

    expect(array_keys($goals))->toEqualCanonicalizing([
        'season_sessions_completed',
        'season_quality_completed',
        'season_longest_long_run_km',
        'season_rest_honored',
    ])
        ->and($goals['season_longest_long_run_km'])->toBe($readinessKm);
})->with([
    '10K' => [10_000, 40.0, 12.0],
    'half' => [21_097, 40.0, 18.0],
    'marathon' => [42_195, 80.0, 30.0],
    '10K capped at half the week' => [10_000, 16.0, 8.0],
]);

it('appends the block goals once the block opens, and only once', function (): void {
    $user = User::factory()->create();
    seasonServiceWeeks($user, 30.0);
    RaceGoal::factory()->for($user)->create(['race_date' => '2027-03-08', 'distance_m' => 10_000]);
    $season = $this->service->ensureCurrent($user, Carbon::today());

    Carbon::setTestNow('2026-11-22 08:00:00');
    $this->service->ensureCurrent($user, Carbon::today());
    expect(SeasonGoal::query()->where('season_id', $season->id)->count())->toBe(4);

    Carbon::setTestNow('2026-11-23 08:00:00');
    $this->service->ensureCurrent($user, Carbon::today());

    $goalQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$goalQueries): void {
        $goalQueries += str_contains($query->sql, 'season_goals') ? 1 : 0;
    });
    $this->service->ensureCurrent($user, Carbon::today());
    expect($goalQueries)->toBe(0)
        ->and($season->fresh()->block_goals_appended_at)->not->toBeNull();

    $metrics = SeasonGoal::query()->where('season_id', $season->id)->pluck('metric');
    $peakWeekKm = collect(app(SeasonSummaryBuilder::class)->plannedWeeks($user, $season->fresh()))
        ->where('zone', 'block')
        ->max('planned_km');

    expect($metrics)->toHaveCount(6)
        ->and($metrics->duplicates())->toBeEmpty()
        ->and($metrics)->toContain('season_race_goal_met', 'season_peak_weekly_km')
        ->and(SeasonGoal::query()->where('season_id', $season->id)->where('metric', 'season_peak_weekly_km')->value('target'))
        ->toBe(round($peakWeekKm, 1));
});

it('serves the under-ready line once, counting the block rows through race week', function (int $daysOut, int $distanceM, string $line): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addDays($daysOut)->toDateString(), 'distance_m' => $distanceM]);
    $season = $this->service->ensureCurrent($user, Carbon::today());

    expect($this->service->takeUnderReadyLine($season))->toBe($line)
        ->and($season->fresh()->under_ready_noted_at)->not->toBeNull()
        ->and($this->service->takeUnderReadyLine($season->fresh()))->toBeNull();
})->with([
    '10K with twelve rows to race week' => [77, 10_000, "Twelve weeks is tighter than I'd pick for this one, so we build what we can and race what we've built."],
    'marathon with eighteen rows to race week' => [119, 42_195, "Eighteen weeks is tighter than I'd pick for this one, so we build what we can and race what we've built."],
    'half raced in the season\'s first week' => [5, 21_097, "One week is tighter than I'd pick for this one, so we build what we can and race what we've built."],
]);

it('says nothing about readiness for a full block or a season with no race', function (): void {
    $fullBlock = User::factory()->create();
    RaceGoal::factory()->for($fullBlock)->create(['race_date' => Carbon::today()->addWeeks(15)->toDateString(), 'distance_m' => 10_000]);
    $farOut = User::factory()->create();
    RaceGoal::factory()->for($farOut)->create(['race_date' => Carbon::today()->addWeeks(30)->toDateString(), 'distance_m' => 42_195]);

    foreach ([$fullBlock, $farOut, User::factory()->create()] as $user) {
        $season = $this->service->ensureCurrent($user, Carbon::today());

        expect($this->service->takeUnderReadyLine($season))->toBeNull()
            ->and($season->fresh()->under_ready_noted_at)->toBeNull();
    }
});
