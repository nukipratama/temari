<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use App\Actions\AI\RenarrateAfterMakeUp;
use App\Enums\PlanRegenerationReason;
use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Http\Controllers\PlanController;
use App\Http\Requests\UpdatePlannedSessionRequest;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\Run\RegeneratePlanJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RecommendationRevision;
use App\Models\RecommendationView;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\Run\Plan\PlanRegenerateCooldown;
use App\Services\Run\Plan\MakeUpService;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanAdapter;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\SessionMatcher;
use App\Support\Numeric;
use App\Support\TrainingDisclaimer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-10 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('paints a fresh user\'s plan body at once, creating the season and its 5 goals, then serves a partial reload\'s props alone', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/plan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Plan')
            ->has('race')
            ->where('raceCard', null)
            ->missing('raceSupportedTimeSec')
            ->missing('raceOutcomeCandidate')
            ->has('sessionsPerWeek')
            ->has('season')
            ->where('season.week_index', 1)
            ->missing('season.is_race_oriented')
            ->missing('season.block_opens_on')
            ->missing('season.goals')
            ->missing('season.record')
            ->where('disclaimerLine', TrainingDisclaimer::SHORT)
            ->missing('disclaimer')
            ->where('weeks', [])
            ->has('seasonSummary')
            ->where('seasonAdherencePct', null)
            ->where('adaptation', null)
            ->etc());

    $season = Season::query()->where('user_id', $user->id)->sole();
    expect($season->goals()->count())->toBe(5);

    // The whole action runs again on every partial request, so every prop
    // is a closure and the partial must resolve only the ones it asked for.
    $response = $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks,seasonSummary,seasonAdherencePct'))
        ->assertSuccessful()
        ->assertJsonPath('component', 'Plan')
        ->assertJsonPath('props.weeks', [])
        ->assertJsonStructure(['props' => ['seasonSummary' => [['week_start', 'phase', 'type', 'planned_km']]]])
        ->assertJsonPath('props.seasonSummary.0.type', 'current');

    expect($response->json('props'))->toHaveKeys(['seasonSummary', 'seasonAdherencePct'])
        ->and($response->json('props'))->not->toHaveKey('season')
        ->and($response->json('props'))->not->toHaveKey('sessionsPerWeek');
});

it('serves a week older than the loaded window only on the partial reload that asks for it', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-07-07', 'session_type' => SessionType::Easy]);

    $this->actingAs($user)->get('/plan?week=2026-07-06')
        ->assertInertia(fn (Assert $page) => $page->missing('pastWeek')->etc());

    $this->actingAs($user)
        ->get('/plan?week=2026-07-06', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'pastWeek'))
        ->assertSuccessful()
        ->assertJsonPath('props.pastWeek.week_start', '2026-07-06')
        ->assertJsonPath('props.pastWeek.type', 'history')
        ->assertJsonPath('props.pastWeek.days.0.date', '2026-07-07')
        ->assertJsonMissingPath('props.weeks');
});

it('paints the race card at once and loads its supported time and outcome run in their own deferred group', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => Carbon::today()->addWeeks(8)->toDateString(), 'distance_m' => 10_000, 'goal_time_sec' => 3_600]);

    $this->actingAs($user)->get('/plan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('raceCard.state', 'ahead')
            ->where('raceCard.days_to_race', 56)
            ->missing('raceSupportedTimeSec')
            ->missing('raceOutcomeCandidate')
            ->loadDeferredProps('race', fn (Assert $reload) => $reload
                ->has('raceSupportedTimeSec')
                ->where('raceOutcomeCandidate', null)
                ->missing('weeks')
                ->missing('season')
                ->etc()));
});

it('regenerating populates the plan, redirects with a success flash and renders the generated weeks', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/plan/regenerate')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PlannedSession::query()->where('user_id', $user->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful()
        ->assertJsonPath('component', 'Plan')
        ->assertJsonPath('props.weeks', fn (mixed $weeks): bool => is_array($weeks) && $weeks !== []);
});

it('sends the recommendation token for today only and serves any other day\'s on its own endpoint, which records the same view', function (): void {
    $user = User::factory()->create();
    app(Periodizer::class)->regenerate($user);
    $today = Carbon::today()->toDateString();
    $tomorrow = Carbon::today()->addDay()->toDateString();

    $weeks = $this->actingAs($user)->get('/plan')->assertSuccessful()->inertiaProps('weeks');
    $tokenDates = collect(arrayOf($weeks))
        ->flatMap(fn (mixed $week): array => arrayOf(arrayOf($week)['days']))
        ->filter(fn (mixed $day): bool => isset(arrayOf($day)['recommendation_token']))
        ->map(fn (mixed $day): mixed => arrayOf($day)['date'])
        ->values()
        ->all();
    expect($tokenDates)->toBe([$today]);

    $token = $this->actingAs($user)->getJson(route('plan.days.recommendation', $tomorrow))
        ->assertSuccessful()
        ->assertJsonStructure(['token'])
        ->json('token');

    $this->actingAs($user)->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => (string) Str::uuid()])->assertNoContent();
    expect(RecommendationRevision::query()->sole()->date->toDateString())->toBe($tomorrow)
        ->and(RecommendationView::query()->count())->toBe(1);
});

it('has no recommendation token for a day outside the athlete\'s plan, another athlete\'s day or a bad date', function (): void {
    $owner = User::factory()->create();
    app(Periodizer::class)->regenerate($owner);
    $tomorrow = Carbon::today()->addDay()->toDateString();

    $this->actingAs(User::factory()->create())->getJson(route('plan.days.recommendation', $tomorrow))->assertNotFound();
    $this->actingAs($owner)->getJson(route('plan.days.recommendation', Carbon::today()->addYears(2)->toDateString()))->assertNotFound();
    $this->actingAs($owner)->getJson(route('plan.days.recommendation', 'not-a-date'))->assertNotFound();
    auth()->logout();
    $this->getJson(route('plan.days.recommendation', $tomorrow))->assertUnauthorized();
});

it('updating a session automatically pins it, so the next regeneration leaves it alone', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => 'tempo',
        'pinned' => false,
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$session->id}", ['skipped' => true])
        ->assertRedirect();

    $fresh = $session->refresh();
    expect($fresh->skipped)->toBeTrue()
        ->and($fresh->pinned)->toBeTrue()
        ->and($fresh->session_type->value)->toBe('tempo');
});

it('answers an edit to another athlete\'s session with 404, leaving it untouched', function (): void {
    $session = PlannedSession::factory()->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'skipped' => false,
    ]);

    $this->actingAs(User::factory()->create())
        ->patch("/plan/sessions/{$session->id}", ['skipped' => true])
        ->assertNotFound();

    expect($session->refresh()->skipped)->toBeFalse();
});

it('allows an explicit unpin alongside an edit', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->pinned()->create([
        'date' => Carbon::today()->addDay()->toDateString(),
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$session->id}", ['pinned' => false]);

    expect($session->refresh()->pinned)->toBeFalse();
});

it('restores a skipped future session to scoring and regeneration', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $date = Carbon::today()->addDay()->toDateString();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => $date,
        'session_type' => SessionType::Tempo,
        'status' => PlannedSessionStatus::Planned,
        'skipped' => true,
        'pinned' => true,
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$session->id}", ['skipped' => false, 'pinned' => false])
        ->assertRedirect();

    $restored = $session->refresh();
    expect($restored->skipped)->toBeFalse()
        ->and($restored->pinned)->toBeFalse()
        ->and($restored->isExcused())->toBeFalse();

    app(Periodizer::class)->regenerate($user);

    $regenerated = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', $date)->sole();
    expect($regenerated->id)->not->toBe($session->id)
        ->and($regenerated->skipped)->toBeFalse()
        ->and($regenerated->pinned)->toBeFalse();
});

it('cuts block and delete: the delete route is gone and a block attempt is a no-op', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => 'tempo',
    ]);

    // The route is gone, not merely unlinked.
    $this->actingAs($user)
        ->delete("/plan/sessions/{$session->id}")
        ->assertMethodNotAllowed();

    // session_type is no longer a validated field, so a block attempt is a no-op.
    $this->actingAs($user)->patch("/plan/sessions/{$session->id}", ['session_type' => 'rest']);

    expect($session->refresh()->session_type->value)->toBe('tempo')
        ->and(PlannedSession::query()->find($session->id))->not->toBeNull();
});

it('moves a session by swapping it with whatever already sits on the target day', function (): void {
    $user = User::factory()->create();
    $from = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => 'tempo',
        'pinned' => false,
    ]);
    $to = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
        'session_type' => 'rest',
        'pinned' => false,
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$from->id}", ['date' => $to->date->toDateString()])
        ->assertRedirect();

    // Each row keeps its own calendar slot; what they prescribe is what moves.
    expect($from->refresh()->date->toDateString())->toBe(Carbon::today()->addDay()->toDateString())
        ->and($from->refresh()->session_type->value)->toBe('rest')
        ->and($to->refresh()->date->toDateString())->toBe(Carbon::today()->addDays(2)->toDateString())
        ->and($to->refresh()->session_type->value)->toBe('tempo')
        ->and($from->refresh()->pinned)->toBeTrue()
        ->and($to->refresh()->pinned)->toBeTrue();
});

it('moves a generated quality prescription with its whole workout', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500,
        'set_at' => '2026-08-01',
    ]);
    seedConfirmedEffort($user, 5000, 1500, Carbon::parse('2026-08-01'));
    Season::factory()->for($user)->create(['anchor_weekly_volume_km' => 28.0]);
    app(Periodizer::class)->regenerate($user);

    $movable = collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))
        ->flatMap(fn (array $week): array => arrayOf($week['days']))
        ->first(fn (array $day): bool => in_array($day['session_type'], ['tempo', 'interval'], true) && $day['move_targets'] !== []);
    $quality = PlannedSession::query()
        ->whereKey($movable['id'])
        ->where('prescribed_hard_minutes', '>', 0)
        ->whereNotNull('prescribed_pace_sec_per_km')
        ->firstOrFail();
    $rest = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereDate('date', $movable['move_targets'][0])
        ->firstOrFail();
    $qualityWorkout = $quality->only(PlannedSession::WORKOUT_TRANSFER_FIELDS);
    $restWorkout = $rest->only(PlannedSession::WORKOUT_TRANSFER_FIELDS);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$quality->id}", ['date' => $rest->date->toDateString()])
        ->assertRedirect();

    expect($quality->refresh()->only(PlannedSession::WORKOUT_TRANSFER_FIELDS))->toBe($restWorkout)
        ->and($rest->refresh()->only(PlannedSession::WORKOUT_TRANSFER_FIELDS))->toBe($qualityWorkout)
        ->and($quality->refresh()->pinned)->toBeTrue()
        ->and($rest->refresh()->pinned)->toBeTrue();
});

it('moves race distance with the race workout without changing the goal date', function (): void {
    $user = User::factory()->create();
    $raceDate = Carbon::today()->addDay();
    $goal = RaceGoal::factory()->for($user)->create([
        'race_date' => $raceDate,
        'distance_m' => 10_000,
    ]);
    $race = PlannedSession::factory()->for($user)->create([
        'date' => $raceDate->toDateString(),
        'session_type' => SessionType::Race,
        'race_distance_m' => $goal->distance_m,
    ]);
    $rest = PlannedSession::factory()->for($user)->rest()->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$race->id}", ['date' => $rest->date->toDateString()])
        ->assertRedirect();

    expect($race->refresh()->race_distance_m)->toBeNull()
        ->and($rest->refresh()->session_type)->toBe(SessionType::Race)
        ->and($rest->refresh()->race_distance_m)->toBe(10_000)
        ->and($goal->refresh()->race_date->toDateString())->toBe($raceDate->toDateString());
});

it('rolls back both rows when a session swap fails after its first write', function (): void {
    $user = User::factory()->create();
    $quality = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => SessionType::Tempo,
        'prescribed_hard_minutes' => 20,
        'prescribed_pace_band' => 'threshold',
        'prescribed_pace_sec_per_km' => 330,
        'prescription_reason' => 'conservative start',
    ]);
    $rest = PlannedSession::factory()->for($user)->rest()->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
    ]);
    $qualityBefore = $quality->refresh()->getAttributes();
    $restBefore = $rest->refresh()->getAttributes();
    $failed = false;
    DB::listen(function (QueryExecuted $query) use (&$failed): void {
        if (! $failed && str_starts_with(strtolower($query->sql), 'update `planned_sessions`')) {
            $failed = true;
            throw new RuntimeException('planned session swap failed');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)->patch("/plan/sessions/{$quality->id}", ['date' => $rest->date->toDateString()]))
        ->toThrow(RuntimeException::class, 'planned session swap failed');

    expect($quality->refresh()->getAttributes())->toBe($qualityBefore)
        ->and($rest->refresh()->getAttributes())->toBe($restBefore);
});

it('rejects a session edit when regeneration replaced its bound row', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => SessionType::Tempo,
        'pinned' => false,
    ]);
    $staleSession = clone $session;

    app(Periodizer::class)->regenerate($user);

    /** @var UpdatePlannedSessionRequest&MockInterface $request */
    $request = Mockery::mock(UpdatePlannedSessionRequest::class)->makePartial();
    $request->shouldReceive('validated')->once()->andReturn([
        'date' => Carbon::today()->addDays(2)->toDateString(),
    ]);
    $request->setUserResolver(fn (): User => $user);
    $this->withoutExceptionHandling();

    expect(fn () => app(PlanController::class)->update(
        $request,
        $staleSession,
        app(Periodizer::class),
        app(SessionMatcher::class),
        app(MakeUpService::class),
        app(RenarrateAfterMakeUp::class),
        app(AnalysisService::class),
    ))->toThrow(HttpException::class, 'This plan changed while you were editing. Reload and try again.');

    expect(PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereDate('date', Carbon::today()->addDay()->toDateString())
        ->value('id'))->not->toBe($session->id);
});

it('does not report a session edit as saved while regeneration holds the per-user lock', function (): void {
    Sleep::fake(syncWithCarbon: true);
    $user = User::factory()->create();
    $source = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => SessionType::Tempo,
    ]);
    $destination = PlannedSession::factory()->for($user)->rest()->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
    ]);
    $lock = Cache::lock("plan-reconciliation:{$user->id}", 3600);
    expect($lock->get())->toBeTrue();

    $this->actingAs($user)
        ->patch("/plan/sessions/{$source->id}", ['date' => $destination->date->toDateString()])
        ->assertRedirect()
        ->assertSessionHas('info');

    $lock->release();
    expect($source->refresh()->session_type)->toBe(SessionType::Tempo)
        ->and($destination->refresh()->session_type)->toBe(SessionType::Rest);
});

it('queues a manual regeneration when the per-user lock stays busy', function (): void {
    Sleep::fake(syncWithCarbon: true);
    Bus::fake();
    $user = User::factory()->create();
    $lock = Cache::lock("plan-reconciliation:{$user->id}", 3600);
    expect($lock->get())->toBeTrue();

    $this->actingAs($user)
        ->post('/plan/regenerate')
        ->assertRedirect()
        ->assertSessionHas('info');

    $lock->release();
    Bus::assertDispatched(fn (RegeneratePlanJob $job): bool =>
    $job->userId === $user->id && $job->reason === PlanRegenerationReason::Manual);
    expect(app(PlanRegenerateCooldown::class)->remaining($user))->not->toBeNull();
});

it('answers the 11th manual regenerate within a minute with 429, the cooldown still answering the rest', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/plan/regenerate')->assertSessionHas('success');

    foreach (range(2, 10) as $attempt) {
        $this->actingAs($user)->post('/plan/regenerate')
            ->assertRedirect()
            ->assertSessionHas('info');
    }

    $this->actingAs($user)->post('/plan/regenerate')->assertTooManyRequests();
});

/**
 * Reported from prod: two sessions on one day rendered as a single
 * "12 km · 55:00" — the day's summed distance beside only the longer run's
 * duration. Each run now carries its own figures, while actual_km stays the
 * day total that compliance scores against.
 */
it('returns every run of a two-session day, each with its own distance and time', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => 'easy',
    ]);

    $morning = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($morning)->create([
        'start_date_local' => Carbon::today()->setTime(6, 0),
        'distance' => 5000,
        'moving_time' => 1380,
        'elapsed_time' => 1380,
    ]);
    $evening = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($evening)->create([
        'start_date_local' => Carbon::today()->setTime(18, 0),
        'distance' => 7000,
        'moving_time' => 3300,
        'elapsed_time' => 3300,
    ]);

    $response = $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful();

    $today = arrayOf(collect(arrayOf($response->json('props.weeks')))
        ->flatMap(fn (mixed $week): array => arrayOf(arrayOf($week)['days']))
        ->firstWhere('date', Carbon::today()->toDateString()));
    $activities = arrayOf($today['activities']);

    expect(Numeric::float($today['actual_km']))->toBe(12.0)
        ->and($activities)->toHaveCount(2)
        // Oldest first, so the list reads in the order they were run.
        ->and(arrayOf($activities[0])['id'])->toBe($morning->id)
        ->and(Numeric::float(arrayOf($activities[0])['km']))->toBe(5.0)
        ->and(arrayOf($activities[0])['seconds'])->toBe(1380)
        ->and(arrayOf($activities[1])['id'])->toBe($evening->id)
        ->and(Numeric::float(arrayOf($activities[1])['km']))->toBe(7.0)
        ->and(arrayOf($activities[1])['seconds'])->toBe(3300);
});

// A budget, not an exact count: it may move with the page, but a memoization
// regression (the active race resolving once per collaborator again) lands here
// as several statements at once. The initial leg is the expensive one: the
// plan engine, the season service and the narration requester all run there.
it('paints the Plan page and resolves its deferred race group inside their query budgets', function (): void {
    $user = planBudgetFixture();

    $queries = 0;
    $readinessQueries = ['stress' => 0];
    DB::listen(function (QueryExecuted $query) use (&$queries, &$readinessQueries): void {
        $queries++;
        if (str_contains($query->sql, '`activity_details`.`stream_summary`')
            && str_contains($query->sql, '`activity_details`.`has_heartrate`')) {
            $readinessQueries['stress']++;
        }
    });

    $this->actingAs($user)->get('/plan')->assertSuccessful();

    expect($queries)->toBeLessThanOrEqual(41);
    expect($readinessQueries)->toBe(['stress' => 1]);

    $headers = inertiaPartialHeaders(
        $this->actingAs($user),
        '/plan',
        'Plan',
        'raceSupportedTimeSec,raceOutcomeCandidate',
    );
    $queries = 0;

    $this->actingAs($user)->get('/plan', $headers)->assertSuccessful();

    expect($queries)->toBeLessThanOrEqual(1);
});

function planBudgetFixture(): User
{
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(10),
        'completed_at' => null,
    ]);

    seedPastSeasonWeeks($user, $race);

    foreach (range(1, 6) as $daysAgo) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::today()->subDays($daysAgo),
            'distance' => 8000.0,
            'trimp_edwards' => 70.0,
        ]);
    }

    foreach (range(1, 6) as $weeksAgo) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($weeksAgo)->toDateString(),
            'distance_km' => 30.0,
        ]);
    }

    return $user;
}

it('refuses to move a day that has already happened, leaving both rows as they were', function (): void {
    $user = User::factory()->create();
    $from = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->subDay()->toDateString(),
        'session_type' => 'tempo',
        'status' => PlannedSessionStatus::Done,
    ]);
    $to = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => 'rest',
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$from->id}", ['date' => $to->date->toDateString()])
        ->assertSessionHasErrors('date');

    expect($from->refresh()->session_type->value)->toBe('tempo')
        ->and($to->refresh()->session_type->value)->toBe('rest');
});

it('refuses to move a session onto a day that is not a rest day', function (): void {
    $user = User::factory()->create();
    $from = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => 'tempo',
    ]);
    $to = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
        'session_type' => 'race',
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$from->id}", ['date' => $to->date->toDateString()])
        ->assertSessionHasErrors('date');

    expect($from->refresh()->session_type->value)->toBe('tempo')
        ->and($to->refresh()->session_type->value)->toBe('race');
});

/**
 * @param  array<string, string>  $types  Y-m-d => session type
 * @param  array<string, array<string, mixed>>  $attributesByDate
 * @return array<string, PlannedSession>
 */
function planWeekRows(User $user, array $types, array $attributesByDate = []): array
{
    $rows = [];
    foreach ($types as $date => $type) {
        $rows[$date] = PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'session_type' => $type,
            ...($attributesByDate[$date] ?? []),
        ]);
    }

    return $rows;
}

it('briefs today only when a restore brings today\'s session back', function (string $date, bool $wasSkipped, bool $skipped, bool $demo, bool $rebriefs): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
    $user = User::factory()->create(['is_demo' => $demo]);
    $rows = planWeekRows($user, ['2026-08-12' => 'easy', '2026-08-14' => 'easy'], [
        $date => ['skipped' => $wasSkipped],
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows[$date]->id}", ['skipped' => $skipped])
        ->assertSessionHasNoErrors();

    expect(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->where('discriminator', '2026-08-12')->exists())->toBe($rebriefs);
})->with([
    'skip today' => ['2026-08-12', false, true, false, false],
    'restore today' => ['2026-08-12', true, false, false, true],
    'skip today again' => ['2026-08-12', true, true, false, false],
    'skip a later day' => ['2026-08-14', false, true, false, false],
    'demo restores today' => ['2026-08-12', true, false, true, false],
]);

it('briefs today once on a restore when no briefing was narrated', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-12' => 'easy'], ['2026-08-12' => ['skipped' => true]]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-12']->id}", ['skipped' => false, 'pinned' => false])
        ->assertSessionHasNoErrors();

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});

it('rebriefs today when a plain move takes a session off today or onto it', function (string $from, string $to, bool $rebriefs, bool $demo): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
    $user = User::factory()->create(['is_demo' => $demo]);
    $rows = planWeekRows($user, [
        '2026-08-12' => $from === '2026-08-12' ? 'easy' : 'rest',
        '2026-08-13' => 'rest',
        '2026-08-14' => 'easy',
        '2026-08-15' => 'rest',
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows[$from]->id}", ['date' => $to])
        ->assertSessionHasNoErrors();

    expect(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->where('discriminator', '2026-08-12')->exists())->toBe($rebriefs);
})->with([
    'today away' => ['2026-08-12', '2026-08-13', true, false],
    'onto today' => ['2026-08-14', '2026-08-12', true, false],
    'between later days' => ['2026-08-14', '2026-08-15', false, false],
    'demo, today away' => ['2026-08-12', '2026-08-13', false, true],
]);

it('refuses to skip or restore a past day', function (bool $skipped): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-11' => 'easy'], [
        '2026-08-11' => ['status' => PlannedSessionStatus::Missed, 'skipped' => ! $skipped],
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-11']->id}", ['skipped' => $skipped])
        ->assertSessionHasErrors('skipped');

    expect($rows['2026-08-11']->refresh()->skipped)->toBe(! $skipped);
})->with([true, false]);

/** @return Collection<array-key, mixed> */
function planDaysByDate(User $user): Collection
{
    return collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))
        ->flatMap(fn (array $week): array => arrayOf($week['days']))
        ->keyBy('date');
}

function makeUpRun(User $user, string $date, float $km): void
{
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse("{$date} 06:00:00"),
        'start_date_utc' => Carbon::parse("{$date} 05:00:00", 'UTC'),
        'distance' => $km * 1000,
        'moving_time' => (int) round($km * 390),
        'elapsed_time' => (int) round($km * 390),
    ]);
}

it('refuses to skip a missed session made up onto today before it is run', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-11' => 'easy', '2026-08-12' => 'rest'], [
        '2026-08-11' => ['status' => PlannedSessionStatus::Missed, 'compliance_score' => 0, 'distance_score' => 0],
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-11']->id}", ['date' => '2026-08-12'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-12']->id}", ['skipped' => true])
        ->assertSessionHasErrors('skipped');

    expect($rows['2026-08-12']->refresh()->skipped)->toBeFalse()
        ->and(arrayOf(arrayOf(planDaysByDate($user)['2026-08-12'])['actions'])['skip'])->toBeFalse();
});

it('makes up the same missed easy on Thursday, onto Wednesday\'s past rest day that was run', function (): void {
    Carbon::setTestNow('2026-08-13 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-11' => 'easy', '2026-08-12' => 'rest'], [
        '2026-08-11' => ['status' => PlannedSessionStatus::Missed, 'compliance_score' => 0, 'distance_score' => 0],
        '2026-08-12' => ['status' => PlannedSessionStatus::Done, 'ran_anyway' => true],
    ]);
    makeUpRun($user, '2026-08-12', 5.0);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-11']->id}", ['date' => '2026-08-12'])
        ->assertSessionHasNoErrors();

    expect($rows['2026-08-12']->refresh()->session_type)->toBe(SessionType::Easy)
        ->and($rows['2026-08-12']->refresh()->status->isCredited())->toBeTrue()
        ->and($rows['2026-08-12']->refresh()->ran_anyway)->toBeFalse()
        ->and($rows['2026-08-11']->refresh()->made_up_on?->toDateString())->toBe('2026-08-12');
});

it('counts the made-up day in next week\'s adherence and leaves the emptied day out', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-11' => 'easy', '2026-08-12' => 'rest'], [
        '2026-08-11' => ['status' => PlannedSessionStatus::Missed, 'compliance_score' => 0, 'distance_score' => 0],
    ]);
    makeUpRun($user, '2026-08-12', 5.0);
    $this->actingAs($user)->patch("/plan/sessions/{$rows['2026-08-11']->id}", ['date' => '2026-08-12']);

    Carbon::setTestNow('2026-08-17 08:00:00');
    $adherence = app(PlanAdapter::class)->forWeek($user, Carbon::parse('2026-08-17'), Carbon::today(), null)['adherence_pct'];

    expect($adherence)->toBe(min(100, $rows['2026-08-12']->refresh()->distance_score))
        ->and($adherence)->toBeGreaterThan(0);
});

it('never clamps a future day, only today, even under a restricted readiness ceiling', function (): void {
    $user = User::factory()->create();
    seedDemandingRunYesterday($user);
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
        'session_type' => 'interval',
    ]);

    $response = $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful();

    $weeks = arrayOf($response->json('props.weeks'));
    $futureDay = arrayOf(collect($weeks)
        ->flatMap(fn (mixed $week): array => arrayOf(arrayOf($week)['days']))
        ->firstWhere('date', Carbon::today()->addDays(2)->toDateString()));

    expect($futureDay['session_type'])->toBe('interval')
        ->and($futureDay['eased_from'])->toBeNull();
});

it('credits the run a skipped session is made up onto, rather than excusing it', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-10' => 'rest', '2026-08-12' => 'easy'], [
        '2026-08-10' => ['status' => PlannedSessionStatus::Done, 'ran_anyway' => true],
        '2026-08-12' => ['skipped' => true, 'status' => PlannedSessionStatus::Skip],
    ]);
    makeUpRun($user, '2026-08-10', 5.0);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-12']->id}", ['date' => '2026-08-10'])
        ->assertSessionHasNoErrors();

    $monday = $rows['2026-08-10']->refresh();
    expect($monday->session_type)->toBe(SessionType::Easy)
        ->and($monday->skipped)->toBeFalse()
        ->and($monday->status->isCredited())->toBeTrue()
        ->and($monday->distance_score)->toBeGreaterThan(0);
});

it('re-narrates the made-up day\'s run and today\'s briefing after a make-up move', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-11' => 'easy', '2026-08-12' => 'rest'], [
        '2026-08-11' => ['status' => PlannedSessionStatus::Missed, 'compliance_score' => 0, 'distance_score' => 0],
    ]);
    makeUpRun($user, '2026-08-12', 5.0);
    $run = Activity::query()->where('user_id', $user->id)->sole();

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-11']->id}", ['date' => '2026-08-12'])
        ->assertSessionHasNoErrors();

    Bus::assertDispatched(fn (AnalyzeActivityJob $job): bool => $job->subjectId === $run->id
        && $job->delay === AnalysisService::PLAN_EDIT_DELAY_SECONDS);
    Bus::assertDispatched(fn (AnalyzeBriefingMascotVoiceJob $job): bool => $job->delay === AnalysisService::PLAN_EDIT_DELAY_SECONDS);
});

it('counts only plan edits against the plan-edit budget, and answers the 21st within a minute with 429', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-14' => 'easy']);

    foreach (range(1, 5) as $view) {
        $this->actingAs($user)->postJson('/plan/recommendations/shown', [])->assertUnprocessable();
    }

    foreach (range(1, 20) as $edit) {
        $this->actingAs($user)
            ->patch("/plan/sessions/{$rows['2026-08-14']->id}", ['skipped' => $edit % 2 === 1])
            ->assertRedirect();
    }

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-14']->id}", ['skipped' => false])
        ->assertTooManyRequests();
});

it('restores today without invalidating a briefing already narrated', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-12' => 'easy']);
    $this->mock(AnalysisService::class)
        ->shouldReceive('requestBriefing')
        ->once()
        ->withArgs(fn (User $asked, string $date, bool $invalidate, ?int $delaySeconds): bool => $asked->is($user)
            && $date === '2026-08-12'
            && $invalidate === false
            && $delaySeconds === AnalysisService::PLAN_EDIT_DELAY_SECONDS);

    foreach ([true, false] as $skipped) {
        $this->actingAs($user)
            ->patch("/plan/sessions/{$rows['2026-08-12']->id}", ['skipped' => $skipped, 'pinned' => false])
            ->assertSessionHasNoErrors();
    }

    expect($rows['2026-08-12']->refresh()->skipped)->toBeFalse();
});

it('queues nothing for the demo athlete however often today is toggled', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
    $user = User::factory()->create(['is_demo' => true]);
    $rows = planWeekRows($user, ['2026-08-12' => 'easy']);

    foreach ([true, false, true] as $skipped) {
        $this->actingAs($user)
            ->patch("/plan/sessions/{$rows['2026-08-12']->id}", ['skipped' => $skipped])
            ->assertSessionHasNoErrors();
    }

    Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
    expect(Analysis::query()->where('analysis_type', AnalysisType::BriefingMascotVoice)->exists())->toBeFalse();
});

it('rebriefs a narrated today on a move onto or off it only once its Reread cooldown has run out', function (string $from, string $to, bool $cooling): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    Bus::fake();
    $user = User::factory()->create();
    $rows = planWeekRows($user, [
        '2026-08-12' => $from === '2026-08-12' ? 'easy' : 'rest',
        '2026-08-13' => 'rest',
        '2026-08-14' => 'easy',
    ]);
    $briefing = Analysis::factory()->done('easy run, 2.6 km.')->create([
        'subject_type' => AnalysisType::BRIEFING_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::BriefingMascotVoice,
        'discriminator' => '2026-08-12',
    ]);
    if ($cooling) {
        $briefing->startCooldown();
    }

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows[$from]->id}", ['date' => $to])
        ->assertSessionHasNoErrors();

    if ($cooling) {
        Bus::assertNotDispatched(AnalyzeBriefingMascotVoiceJob::class);
        expect(nonNull($briefing->fresh())->only(['status', 'content', 'updated_at']))->toEqual($briefing->only(['status', 'content', 'updated_at']));
    } else {
        Bus::assertDispatched(fn (AnalyzeBriefingMascotVoiceJob $job): bool => $job->analysisId === $briefing->id
            && $job->delay === AnalysisService::PLAN_EDIT_DELAY_SECONDS);
    }
})->with([
    'today away, cooled' => ['2026-08-12', '2026-08-13', false],
    'onto today, cooled' => ['2026-08-14', '2026-08-12', false],
    'today away, cooling' => ['2026-08-12', '2026-08-13', true],
    'onto today, cooling' => ['2026-08-14', '2026-08-12', true],
]);
