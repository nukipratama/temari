<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\User;
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
        ->patch("/plan/sessions/{$session->id}", ['distance_band' => 'short'])
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
        ->patch("/plan/sessions/{$session->id}", ['distance_band' => 'short'])
        ->assertRedirect();

    $fresh = $session->fresh();
    expect($fresh->distance_band->value)->toBe('short')
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

it('blocking a day (session_type = rest) always clears distance_band and pace_band', function (): void {
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->addDay()->toDateString(),
        'session_type' => 'tempo',
        'distance_band' => 'medium',
        'pace_band' => 'threshold',
    ]);

    $this->actingAs($user)->patch("/plan/sessions/{$session->id}", [
        'session_type' => 'rest',
        'distance_band' => 'medium',
        'pace_band' => 'threshold',
    ]);

    $fresh = $session->fresh();
    expect($fresh->session_type->value)->toBe('rest')
        ->and($fresh->distance_band->value)->toBe('rest')
        ->and($fresh->pace_band)->toBeNull();
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
        'distance_band' => 'short',
        'pace_band' => 'interval',
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
        'distance_band' => 'short',
        'pace_band' => 'interval',
    ]);

    $response = $this->actingAs($user)->get('/plan')->assertSuccessful();

    $weeks = $response->viewData('page')['props']['weeks'];
    $futureDay = collect($weeks)
        ->flatMap(fn (array $week): array => $week['days'])
        ->firstWhere('date', Carbon::today()->addDays(2)->toDateString());

    expect($futureDay['session_type'])->toBe('interval')
        ->and($futureDay['clamp_note'])->toBeNull();
});
