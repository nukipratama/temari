<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

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
            ->has('disclaimer')
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
            ->has('season.goals', 5)
            ->where('season.week_index', 1)
            ->where('season.is_race_oriented', false));

    expect(Season::query()->where('user_id', $user->id)->count())->toBe(1);
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

it('clamps today\'s session against the readiness ceiling without mutating the stored row', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'overreaching',
        'monotony' => 1.0,
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

    // Advisory: the day still reports the interval it was planned as, and the
    // clamp rides beside it saying today is a rest instead.
    expect($todayDay['session_type'])->toBe('interval')
        ->and($todayDay['clamp']['session_type'])->toBe('rest')
        ->and($todayDay['clamp']['note'])->not->toBeNull();

    // The stored row itself is untouched — the clamp is render-only.
    $fresh = $today->fresh();
    expect($fresh->session_type->value)->toBe('interval')
        ->and($fresh->pinned)->toBeFalse();
});

it('never clamps a future day, only today, even at the worst readiness ceiling', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(),
        'form_status' => 'overreaching',
        'monotony' => 1.0,
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
        ->and($futureDay['clamp'])->toBeNull();
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
    ]);
    $evening = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($evening)->create([
        'start_date_local' => Carbon::today()->setTime(18, 0),
        'distance' => 7000,
        'moving_time' => 3300,
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

    expect($queries)->toBeLessThanOrEqual(24);
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
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($user)->get('/plan', $headers)->assertSuccessful();

    expect($queries)->toBeLessThanOrEqual(14);
});

function planBudgetFixture(): User
{
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create([
        'race_date' => Carbon::today()->addWeeks(10),
        'completed_at' => null,
    ]);

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
        ]);
    }

    return $user;
}
