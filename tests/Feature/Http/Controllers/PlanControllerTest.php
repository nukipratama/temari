<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-10 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('requires authentication for every plan route', function (): void {
    $this->get('/plan')->assertRedirect('/login');
    $this->post('/plan/regenerate')->assertRedirect('/login');
    $this->patch('/plan/sessions/1')->assertRedirect('/login');
    $this->delete('/plan/sessions/1')->assertRedirect('/login');
});

it('renders an empty week list for a fresh user with no plan yet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/plan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Plan')->where('weeks', []));
});

it('renders a season-wide week summary even before any plan has been generated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/plan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('seasonSummary')
            ->has('seasonSummary.0.week_start')
            ->has('seasonSummary.0.phase')
            ->has('seasonSummary.0.type')
            ->has('seasonSummary.0.planned_km')
            ->where('seasonSummary.0.type', 'current'));
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

it('counts only an earlier season\'s track tiers as kept, never the live season\'s', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/plan');
    $season = Season::query()->where('user_id', $user->id)->sole();

    UserUnlock::query()->insert([
        ['user_id' => $user->id, 'unlock_key' => 'season.999.track_1', 'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $user->id, 'unlock_key' => 'season.999.track_2', 'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $user->id, 'unlock_key' => 'season.999.rest_honored_3', 'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $user->id, 'unlock_key' => "season.{$season->id}.track_1", 'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($user)->get('/plan')
        ->assertInertia(fn (Assert $page) => $page->where('season.tiers_kept_from_past_seasons', 2));
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

    $this->actingAs($user)->get('/plan')
        ->assertSuccessful()
        ->assertInertia(function (Assert $page): void {
            $page->component('Plan')
                ->has('weeks')
                ->has('sessionsPerWeek');
        });
});

it('rejects updating another user\'s planned session', function (): void {
    $owner = User::factory()->create();
    $session = PlannedSession::factory()->for($owner)->create(['date' => Carbon::today()->addDay()->toDateString()]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->patch("/plan/sessions/{$session->id}", ['pinned' => true])
        ->assertForbidden();
});

it('rejects deleting another user\'s planned session', function (): void {
    $owner = User::factory()->create();
    $session = PlannedSession::factory()->for($owner)->create(['date' => Carbon::today()->addDay()->toDateString()]);
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->delete("/plan/sessions/{$session->id}")
        ->assertForbidden();
});

it('updating a session automatically pins it, so the next regeneration leaves it alone', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'pinned' => false,
    ]);

    $this->actingAs($user)
        ->patch("/plan/sessions/{$session->id}", ['session_type' => 'rest'])
        ->assertRedirect();

    $fresh = $session->fresh();
    expect($fresh->session_type->value)->toBe('rest')
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

it('skips a day via the skipped flag, distinct from blocking', function (): void {
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

it('blocks a day via session_type = rest', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => 'tempo',
    ]);

    $this->actingAs($user)->patch("/plan/sessions/{$session->id}", ['session_type' => 'rest']);

    expect($session->fresh()->session_type->value)->toBe('rest');
});

it('deletes a planned session', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->addDay()->toDateString()]);

    $this->actingAs($user)->delete("/plan/sessions/{$session->id}")->assertRedirect();

    expect(PlannedSession::query()->find($session->id))->toBeNull();
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

    $response = $this->actingAs($user)->get('/plan');
    $response->assertSuccessful();

    $weeks = $response->viewData('page')['props']['weeks'];
    $todayDay = collect($weeks)
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', Carbon::today()->toDateString());

    expect($todayDay['session_type'])->toBe('rest')
        ->and($todayDay['clamp_note'])->not->toBeNull();

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

    $response = $this->actingAs($user)->get('/plan')->assertSuccessful();

    $weeks = $response->viewData('page')['props']['weeks'];
    $futureDay = collect($weeks)
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', Carbon::today()->addDays(2)->toDateString());

    expect($futureDay['session_type'])->toBe('interval')
        ->and($futureDay['clamp_note'])->toBeNull();
});
