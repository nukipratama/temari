<?php

declare(strict_types=1);

use App\Enums\PlanRegenerationReason;
use App\Enums\PlannedSessionStatus;
use App\Enums\IntentVerdict;
use App\Enums\SessionType;
use App\Http\Controllers\PlanController;
use App\Http\Requests\UpdatePlannedSessionRequest;
use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Jobs\Run\RegeneratePlanJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RecommendationView;
use App\Models\RecoveryFeedback;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\ComplianceScorer;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\RecommendationHistory;
use App\Services\Run\Plan\SessionMatcher;
use App\Support\TrainingDisclaimer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-10 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('requires authentication for every plan route', function (): void {
    $this->get('/plan')->assertRedirect('/login');
    $this->post('/plan/regenerate')->assertRedirect('/login');
    $this->patch('/plan/sessions/1')->assertRedirect('/login');
});

it('paints the shell with the plan body deferred', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/plan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Plan')
            ->has('race')
            ->has('sessionsPerWeek')
            ->has('season')
            ->where('disclaimerLine', TrainingDisclaimer::SHORT)
            ->missing('disclaimer')
            ->missing('weeks')
            ->missing('seasonSummary')
            ->missing('seasonAdherencePct')
            ->missing('adaptation')
            ->missing('planNarration')
            ->etc());
});

it('renders an empty week list for a fresh user with no plan yet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful()
        ->assertJsonPath('component', 'Plan')
        ->assertJsonPath('props.weeks', []);
});

it('renders a season-wide week summary even before any plan has been generated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'seasonSummary'))
        ->assertSuccessful()
        ->assertJsonStructure(['props' => ['seasonSummary' => [['week_start', 'phase', 'type', 'planned_km']]]])
        ->assertJsonPath('props.seasonSummary.0.type', 'current');
});

it('creates a season and its 5 goals on a fresh user\'s first Plan view, before any regeneration', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/plan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('season')
            ->where('season.week_index', 1)
            ->missing('season.is_race_oriented')
            ->missing('season.block_opens_on')
            ->missing('season.goals')
            ->missing('season.record'));

    $season = Season::query()->where('user_id', $user->id)->sole();
    expect($season->goals()->count())->toBe(5);
});

it('regenerating populates the plan and redirects with a success flash', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/plan/regenerate')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PlannedSession::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('renders the generated weeks, current week first among non-history', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/plan/regenerate');

    $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful()
        ->assertJsonPath('component', 'Plan')
        ->assertJsonPath('props.weeks', fn (mixed $weeks): bool => is_array($weeks) && $weeks !== []);
});

it('rejects updating another user\'s planned session', function (): void {
    $owner = User::factory()->create();
    $session = PlannedSession::factory()->for($owner)->create(['date' => Carbon::today()->addDay()->toDateString()]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->patch("/plan/sessions/{$session->id}", ['pinned' => true])
        ->assertForbidden();
});

it('updating a session automatically pins it, so the next regeneration leaves it alone', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'pinned' => false,
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$session->id}", ['skipped' => true])
        ->assertRedirect();

    $fresh = $session->fresh();
    expect($fresh->skipped)->toBeTrue()
        ->and($fresh->pinned)->toBeTrue();
});

/**
 * A day already credited (a run logged on it) keeps its narration in sync
 * with an edit — this is the one case a day edit still re-narrates, per #939.
 */
it('attributes an edit\'s re-narration to the athlete, so it re-arms the row\'s retry budget', function (): void {
    Bus::fake();
    $user = creditedRestMoveFixture();

    $this->actingAs($user)->patch('/plan/sessions/'.creditedRestMoveSourceId($user), ['date' => '2026-08-10']);

    Bus::assertDispatched(fn (AnalyzePlanDayVoiceJob $job): bool => $job->origin === AnalysisOrigin::User);
});

it('serves a demo plan edit of a credited day rule-based, dispatching no job', function (): void {
    Bus::fake();
    $user = creditedRestMoveFixture(['is_demo' => true]);

    $this->actingAs($user)->patch('/plan/sessions/'.creditedRestMoveSourceId($user), ['date' => '2026-08-10']);

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    expect(Analysis::query()->where('analysis_type', AnalysisType::PlanDayVoice)->firstOrFail()->status)->toBe(AnalysisStatus::Done);
});

it('serves a demo manual regenerate rule-based, dispatching no season job', function (): void {
    Bus::fake();
    $user = User::factory()->create(['is_demo' => true]);
    Season::factory()->for($user)->create();

    $this->actingAs($user)->post('/plan/regenerate');

    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);
});

/**
 * #939: an edit almost always touches a day still ahead, which has no read
 * to keep in sync — the day gets no narration request at all.
 */
it('requests no narration for an edit to a day that has not been credited', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'status' => PlannedSessionStatus::Planned,
    ]);

    $this->actingAs($user)->patch("/plan/sessions/{$session->id}", ['skipped' => true]);

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
});

it('allows an explicit unpin alongside an edit', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->pinned()->create([
        'date' => Carbon::today()->addDay()->toDateString(),
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$session->id}", ['pinned' => false]);

    expect($session->fresh()->pinned)->toBeFalse();
});

it('skips a day via the skipped flag, leaving the prescribed session in place', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => 'tempo',
        'skipped' => false,
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$session->id}", ['skipped' => true])
        ->assertRedirect();

    $fresh = $session->fresh();
    expect($fresh->skipped)->toBeTrue()
        ->and($fresh->session_type->value)->toBe('tempo');
});

it('restores a skipped future session to scoring and regeneration without requesting a day read', function (): void {
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

    $restored = $session->fresh();
    expect($restored->skipped)->toBeFalse()
        ->and($restored->pinned)->toBeFalse()
        ->and($restored->isExcused())->toBeFalse();
    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);

    app(Periodizer::class)->regenerate($user);

    $regenerated = PlannedSession::query()->where('user_id', $user->id)->whereDate('date', $date)->sole();
    expect($regenerated->id)->not->toBe($session->id)
        ->and($regenerated->skipped)->toBeFalse()
        ->and($regenerated->pinned)->toBeFalse();
});

it('cuts block and delete, per decision P23', function (): void {
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

    expect($session->fresh()->session_type->value)->toBe('tempo')
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
    expect($from->fresh()->date->toDateString())->toBe(Carbon::today()->addDay()->toDateString())
        ->and($from->fresh()->session_type->value)->toBe('rest')
        ->and($to->fresh()->date->toDateString())->toBe(Carbon::today()->addDays(2)->toDateString())
        ->and($to->fresh()->session_type->value)->toBe('tempo')
        ->and($from->fresh()->pinned)->toBeTrue()
        ->and($to->fresh()->pinned)->toBeTrue();
});

it('moves a generated quality prescription with its workout and keeps its rendered and graded intent', function (): void {
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
        ->flatMap(fn (array $week): array => $week['days'])
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

    expect($quality->fresh()->only(PlannedSession::WORKOUT_TRANSFER_FIELDS))->toBe($restWorkout)
        ->and($rest->fresh()->only(PlannedSession::WORKOUT_TRANSFER_FIELDS))->toBe($qualityWorkout)
        ->and($quality->fresh()->pinned)->toBeTrue()
        ->and($rest->fresh()->pinned)->toBeTrue();

    $renderedDay = collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', $rest->date->toDateString());
    $prescribedSegment = collect($renderedDay['segments'])
        ->first(fn (array $segment): bool => $segment['pace_sec_per_km'] === $qualityWorkout['prescribed_pace_sec_per_km']);

    expect($renderedDay['session_type'])->toBe($qualityWorkout['session_type']->value)
        ->and($prescribedSegment)->not->toBeNull();

    $runKm = (float) $renderedDay['distance_km'];
    $runPace = $qualityWorkout['prescribed_pace_sec_per_km'] - 5;
    $paceText = sprintf('%d:%02d', intdiv($runPace, 60), $runPace % 60);
    $summary = collect(['30s', '1min', '3min', '5min', '10min', '20min', '30min', '60min'])
        ->mapWithKeys(fn (string $window): array => ["best_{$window}_pace" => $paceText])
        ->all();
    $shown = json_decode(Crypt::decryptString($renderedDay['recommendation_token']), true, flags: JSON_THROW_ON_ERROR);
    $revision = app(RecommendationHistory::class)->record($user->id, $rest->date->toDateString(), $shown['original'], $shown['effective']);
    RecommendationView::query()->create([
        'recommendation_revision_id' => $revision->id,
        'observation_id' => (string) Str::uuid(),
        'shown_at' => Carbon::parse($rest->date->toDateString().' 00:30:00', 'UTC'),
    ]);
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => $rest->date->copy()->setTime(6, 0),
        'start_date_utc' => Carbon::parse($rest->date->toDateString().' 06:00:00', 'UTC'),
        'distance' => $runKm * 1000,
        'moving_time' => (int) round($runKm * $runPace),
        'elapsed_time' => (int) round($runKm * $runPace),
        'stream_summary' => $summary,
    ]);
    $verdict = app(ComplianceScorer::class)->verdictsFor(
        $user,
        PlannedSession::query()->whereKey($rest->id)->get(),
        $rest->date->copy(),
    )[$rest->date->toDateString()];

    expect($verdict['intent']['verdict'])->toBe(IntentVerdict::Hit);
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

    expect($race->fresh()->race_distance_m)->toBeNull()
        ->and($rest->fresh()->session_type)->toBe(SessionType::Race)
        ->and($rest->fresh()->race_distance_m)->toBe(10_000)
        ->and($goal->fresh()->race_date->toDateString())->toBe($raceDate->toDateString());
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
    $qualityBefore = $quality->fresh()->getAttributes();
    $restBefore = $rest->fresh()->getAttributes();
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

    expect($quality->fresh()->getAttributes())->toBe($qualityBefore)
        ->and($rest->fresh()->getAttributes())->toBe($restBefore);
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
        app(PlanNarrationRequester::class),
        app(SessionMatcher::class),
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
    expect($source->fresh()->session_type)->toBe(SessionType::Tempo)
        ->and($destination->fresh()->session_type)->toBe(SessionType::Rest);
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
    expect(app(PlanNarrationRequester::class)->regenerateCooldownRemaining($user))->not->toBeNull();
});

it('clamps today\'s session against the readiness ceiling without mutating the stored row', function (): void {
    $user = User::factory()->create();
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => Carbon::today()->toDateString(),
        'concerning_pain' => true,
    ]);
    $today = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => 'interval',
        'pinned' => false,
    ]);

    $response = $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful();

    $weeks = $response->json('props.weeks');
    $todayDay = collect($weeks)
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', Carbon::today()->toDateString());

    // Advisory: rest leads today, and the interval it was planned as is the context.
    expect($todayDay['session_type'])->toBe('rest')
        ->and($todayDay['eased_from']['session_type'])->toBe('interval')
        ->and($todayDay['eased_from']['voice'])->not->toBeNull();

    // The stored row itself is untouched — the clamp is render-only.
    $fresh = $today->fresh();
    expect($fresh->session_type->value)->toBe('interval')
        ->and($fresh->pinned)->toBeFalse();
});

it('never clamps a future day, only today, even at the worst readiness ceiling', function (): void {
    $user = User::factory()->create();
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => Carbon::today()->toDateString(),
        'concerning_pain' => true,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDays(2)->toDateString(),
        'session_type' => 'interval',
    ]);

    $response = $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful();

    $weeks = $response->json('props.weeks');
    $futureDay = collect($weeks)
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', Carbon::today()->addDays(2)->toDateString());

    expect($futureDay['session_type'])->toBe('interval')
        ->and($futureDay['eased_from'])->toBeNull();
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

    $today = collect($response->json('props.weeks'))
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', Carbon::today()->toDateString());

    expect((float) $today['actual_km'])->toBe(12.0)
        ->and($today['activities'])->toHaveCount(2)
        // Oldest first, so the list reads in the order they were run.
        ->and($today['activities'][0]['id'])->toBe($morning->id)
        ->and((float) $today['activities'][0]['km'])->toBe(5.0)
        ->and($today['activities'][0]['seconds'])->toBe(1380)
        ->and($today['activities'][1]['id'])->toBe($evening->id)
        ->and((float) $today['activities'][1]['km'])->toBe(7.0)
        ->and($today['activities'][1]['seconds'])->toBe(3300);
});

it('returns an empty activities list for a day with nothing logged', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => 'easy',
    ]);

    $response = $this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful();

    $today = collect($response->json('props.weeks'))
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', Carbon::today()->toDateString());

    expect($today['activities'])->toBe([])
        ->and($today['actual_km'])->toBeNull();
});

it('leaves the eager block alone on the request that only fetches the deferred props', function (): void {
    $user = User::factory()->create();

    // Headers first: the helper resolves the asset version with a real request
    // of its own, which the mock below would otherwise count.
    $headers = inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'seasonSummary,seasonAdherencePct');

    // The whole action runs again on Inertia's partial request, so every prop
    // is a closure and the partial must resolve only the ones it asked for.
    $response = $this->actingAs($user)->get('/plan', $headers)->assertSuccessful();

    expect($response->json('props'))->toHaveKeys(['seasonSummary', 'seasonAdherencePct'])
        ->and($response->json('props'))->not->toHaveKey('season')
        ->and($response->json('props'))->not->toHaveKey('sessionsPerWeek');
});

// A budget, not an exact count: it may move with the page, but a memoization
// regression (the active race resolving once per collaborator again) lands here
// as several statements at once. The deferred leg is the expensive one — the
// plan engine, the season service and the narration requester all run there.
it('paints the Plan shell inside its query budget', function (): void {
    $user = planBudgetFixture();

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($user)->get('/plan')->assertSuccessful();

    // 28: 26 since a race season's creation asks whether its recent load is
    // scored yet, +1 since aiCatchingUp now also asks the hydration gate
    // whether this athlete's history is still coming in, +1 for recent
    // single-run capacity.
    expect($queries)->toBeLessThanOrEqual(28);
});

it('resolves the deferred Plan props inside their query budget', function (): void {
    $user = planBudgetFixture();
    $headers = inertiaPartialHeaders(
        $this->actingAs($user),
        '/plan',
        'Plan',
        'weeks,seasonSummary,seasonAdherencePct,adaptation,planNarration',
    );

    $queries = 0;
    $readinessQueries = ['stress' => 0, 'feedback' => 0];
    DB::listen(function (QueryExecuted $query) use (&$queries, &$readinessQueries): void {
        $queries++;
        if (str_contains($query->sql, '`activity_details`.`stream_summary`')
            && str_contains($query->sql, '`activity_details`.`has_heartrate`')) {
            $readinessQueries['stress']++;
        }
        if (str_contains($query->sql, '`recovery_feedback`')) {
            $readinessQueries['feedback']++;
        }
    });

    $this->actingAs($user)->get('/plan', $headers)->assertSuccessful();

    // 16: was 15 against a fixture with no past season days, so
    // SeasonGamificationContext's grouped read over the season range never
    // ran. The fixture now carries 9 weeks of them (see planBudgetFixture),
    // adding that one query.
    // 20: BriefingContext::prescribedKmToDate reads this week's planned sessions.
    // 21: the supported VDOT reads the athlete's hard efforts.
    expect($queries)->toBeLessThanOrEqual(21);
    expect($readinessQueries)->toBe(['stress' => 1, 'feedback' => 1]);
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

    expect($from->fresh()->session_type->value)->toBe('tempo')
        ->and($to->fresh()->session_type->value)->toBe('rest');
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

    expect($from->fresh()->session_type->value)->toBe('tempo')
        ->and($to->fresh()->session_type->value)->toBe('race');
});

it('keeps a session the athlete pinned to today leading, with the safety advice as a note', function (): void {
    $user = User::factory()->create();
    RecoveryFeedback::query()->create([
        'user_id' => $user->id,
        'date' => Carbon::today()->toDateString(),
        'concerning_pain' => true,
    ]);
    PlannedSession::factory()->for($user)->pinned()->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => 'interval',
    ]);

    $todayDay = collect($this->actingAs($user)
        ->get('/plan', inertiaPartialHeaders($this->actingAs($user), '/plan', 'Plan', 'weeks'))
        ->assertSuccessful()
        ->json('props.weeks'))
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', Carbon::today()->toDateString());

    expect($todayDay['session_type'])->toBe('interval')
        ->and($todayDay['eased_from'])->toBeNull()
        ->and($todayDay['advice_note'])->toContain('reported concerning pain');
});

/**
 * Wednesday 12 Aug: a missed Tuesday Easy and a Monday rest day that carries
 * a run and is already scored.
 */
function creditedRestMoveFixture(array $userAttributes = []): User
{
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create($userAttributes);
    PlannedSession::factory()->for($user)->rest()->create([
        'date' => '2026-08-10',
        'status' => PlannedSessionStatus::Done,
        'ran_anyway' => true,
    ]);
    PlannedSession::factory()->for($user)->create([
        'date' => '2026-08-11',
        'session_type' => SessionType::Easy,
        'status' => PlannedSessionStatus::Missed,
    ]);
    ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
        'start_date_local' => Carbon::parse('2026-08-10 06:00:00'),
        'distance' => 5000,
        'moving_time' => 1800,
        'elapsed_time' => 1800,
    ]);

    return $user;
}

function creditedRestMoveSourceId(User $user): int
{
    return PlannedSession::query()->where('user_id', $user->id)->whereDate('date', '2026-08-11')->value('id');
}

/**
 * @param  array<string, string>  $types  Y-m-d => session type
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

it('moves today\'s unrun session onto a later rest day', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-12' => 'easy', '2026-08-13' => 'rest']);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-12']->id}", ['date' => '2026-08-13'])
        ->assertSessionHasNoErrors();

    expect($rows['2026-08-12']->fresh()->session_type)->toBe(SessionType::Rest)
        ->and($rows['2026-08-13']->fresh()->session_type)->toBe(SessionType::Easy);
});

it('skips and restores today\'s unrun session', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-12' => 'tempo']);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-12']->id}", ['skipped' => true])
        ->assertSessionHasNoErrors();
    expect($rows['2026-08-12']->fresh()->skipped)->toBeTrue();

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-12']->id}", ['skipped' => false, 'pinned' => false])
        ->assertSessionHasNoErrors();
    expect($rows['2026-08-12']->fresh()->skipped)->toBeFalse();
});

it('rebriefs today only when a skip or restore changes today\'s session', function (string $date, bool $wasSkipped, bool $skipped, bool $demo, bool $rebriefs): void {
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
    'skip today' => ['2026-08-12', false, true, false, true],
    'restore today' => ['2026-08-12', true, false, false, true],
    'skip today again' => ['2026-08-12', true, true, false, false],
    'skip a later day' => ['2026-08-14', false, true, false, false],
    'demo skips today' => ['2026-08-12', false, true, true, false],
]);

it('refuses move, skip and restore on a today a run has credited', function (array $payload, string $field): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-12' => 'easy', '2026-08-13' => 'rest'], [
        '2026-08-12' => ['status' => PlannedSessionStatus::Done, 'skipped' => ($payload['skipped'] ?? true) === false],
    ]);
    $before = $rows['2026-08-12']->fresh()->getAttributes();

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-12']->id}", $payload)
        ->assertSessionHasErrors($field);

    expect($rows['2026-08-12']->fresh()->getAttributes())->toBe($before);
})->with([
    'move' => [['date' => '2026-08-13'], 'date'],
    'skip' => [['skipped' => true], 'skipped'],
    'restore' => [['skipped' => false], 'skipped'],
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

    expect($rows['2026-08-11']->fresh()->skipped)->toBe(! $skipped);
})->with([true, false]);

it('refuses to move a credited past day or a day from last week', function (string $date, PlannedSessionStatus $status): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, [$date => 'easy', '2026-08-13' => 'rest'], [
        $date => ['status' => $status],
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows[$date]->id}", ['date' => '2026-08-13'])
        ->assertSessionHasErrors('date');

    expect($rows['2026-08-13']->fresh()->session_type)->toBe(SessionType::Rest);
})->with([
    'credited this week' => ['2026-08-11', PlannedSessionStatus::Done],
    'missed last week' => ['2026-08-09', PlannedSessionStatus::Missed],
]);

it('neither offers nor accepts an empty past rest day as a move target', function (): void {
    Carbon::setTestNow('2026-08-13 08:00:00');
    $user = User::factory()->create();
    $rows = planWeekRows($user, ['2026-08-11' => 'easy', '2026-08-12' => 'rest'], [
        '2026-08-11' => ['status' => PlannedSessionStatus::Missed],
        '2026-08-12' => ['status' => PlannedSessionStatus::Done],
    ]);

    $tuesday = collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', '2026-08-11');
    expect($tuesday['move_targets'])->toBe([])
        ->and($tuesday['actions'])->toBe(['move' => false, 'skip' => false, 'restore' => false]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows['2026-08-11']->id}", ['date' => '2026-08-12'])
        ->assertSessionHasErrors('date');

    expect($rows['2026-08-12']->fresh()->session_type)->toBe(SessionType::Rest);
});

it('keeps a tempo off a rest day beside another hard day, for past and future moves alike', function (string $today, array $types, array $attributes, string $source, string $target): void {
    Carbon::setTestNow("{$today} 08:00:00");
    $user = User::factory()->create();
    $rows = planWeekRows($user, $types, $attributes);
    if ($target < $today) {
        ActivityDetail::factory()->for(Activity::factory()->for($user))->create([
            'start_date_local' => Carbon::parse("{$target} 06:00:00"),
            'distance' => 5000,
        ]);
    }

    $this->actingAs($user)
        ->patch("/plan/sessions/{$rows[$source]->id}", ['date' => $target])
        ->assertSessionHasErrors('date');

    expect($rows[$target]->fresh()->session_type)->toBe(SessionType::Rest);
})->with([
    'future, beside a long run' => ['2026-08-10', ['2026-08-11' => 'tempo', '2026-08-13' => 'rest', '2026-08-14' => 'long'], [], '2026-08-11', '2026-08-13'],
    'future, beside a race' => ['2026-08-10', ['2026-08-11' => 'tempo', '2026-08-13' => 'rest', '2026-08-12' => 'race'], [], '2026-08-11', '2026-08-13'],
    'past, beside an interval' => [
        '2026-08-14',
        ['2026-08-11' => 'tempo', '2026-08-12' => 'rest', '2026-08-13' => 'interval'],
        ['2026-08-11' => ['status' => PlannedSessionStatus::Missed], '2026-08-12' => ['status' => PlannedSessionStatus::Done]],
        '2026-08-11',
        '2026-08-12',
    ],
]);

it('hands each day its actions and move targets from the edit rules', function (): void {
    Carbon::setTestNow('2026-08-12 08:00:00');
    $user = User::factory()->create();
    planWeekRows($user, ['2026-08-11' => 'easy', '2026-08-12' => 'easy', '2026-08-13' => 'rest', '2026-08-14' => 'easy'], [
        '2026-08-11' => ['status' => PlannedSessionStatus::Missed],
    ]);

    $days = collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))
        ->flatMap(fn (array $week): array => $week['days'])
        ->keyBy('date');

    expect($days['2026-08-11']['actions'])->toBe(['move' => true, 'skip' => false, 'restore' => false])
        ->and($days['2026-08-11']['move_targets'])->toBe(['2026-08-13'])
        ->and($days['2026-08-12']['actions'])->toBe(['move' => true, 'skip' => true, 'restore' => false])
        ->and($days['2026-08-13']['actions'])->toBe(['move' => false, 'skip' => false, 'restore' => false])
        ->and($days['2026-08-13']['move_targets'])->toBe([]);
});
