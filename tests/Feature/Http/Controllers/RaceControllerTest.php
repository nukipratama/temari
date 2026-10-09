<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Models\Activity;
use App\Models\PerformanceEvidence;
use App\Models\PersonalRecord;
use App\Services\Run\Plan\SeasonService;
use App\Models\RaceGoal;
use App\Models\PlannedSession;
use App\Services\AI\AnalysisOrigin;
use Illuminate\Support\Carbon;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function racePayload(array $overrides = []): array
{
    return [
        'race_date' => now()->addWeeks(12)->toDateString(),
        'distance_m' => 10_000,
        'goal_time_sec' => 3_000,
        'name' => 'Jakarta 10K',
        ...$overrides,
    ];
}

it('requires authentication for the index', function (): void {
    $this->get('/race')->assertRedirect('/login');
});

it('escapes a race name that would otherwise break out of the embedded page JSON', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['name' => '<!--<script>']);

    $this->withoutVite()->actingAs($user)->get('/race')
        ->assertSuccessful()
        ->assertDontSee('<!--<script>', escape: false)
        ->assertSee('"name":'.json_encode('<!--<script>', JSON_HEX_TAG), escape: false);
});

it('requires authentication for store', function (): void {
    $this->post('/race', racePayload())->assertRedirect('/login');
});

it('renders the page with no race and no projection for a fresh user, never surfacing another user\'s race', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->create(); // another user's active race

    $this->actingAs($user)->get('/race')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Race')
            ->where('race', null)
            ->where('projection', null)
            ->missing('ctlTrend'));
});

it('renders the active race and its projection when one exists', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create(['category' => '5km', 'value_sec' => 1_500.0]);
    $race = RaceGoal::factory()->for($user)->create(['distance_m' => 10_000, 'goal_time_sec' => 3_100]);

    $this->actingAs($user)->get('/race')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Race')
            ->where('race.id', $race->id)
            ->where('race.distance_m', 10_000)
            ->where('projection.sample_size', 1)
            ->where('projection.confidence', 'low')
            ->where('projection.window', 'all'));
});

it('creates the first race for a user with none and busts the shared active-race cache prop', function (): void {
    $user = User::factory()->create();
    $cacheKey = SharedPropCacheKey::ActiveRace->key($user->id);
    Cache::put($cacheKey, ['stale' => true]);

    $this->actingAs($user)
        ->post('/race', racePayload())
        ->assertRedirect()
        ->assertSessionHas('success');

    $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();
    expect($race)->not->toBeNull()
        ->and($race->distance_m)->toBe(10_000)
        ->and($race->goal_time_sec)->toBe(3_000)
        ->and($race->name)->toBe('Jakarta 10K');

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('revises the current race in place by default, keeping its season and history', function (): void {
    $user = User::factory()->create();
    $old = RaceGoal::factory()->for($user)->create(['name' => 'Old race', 'distance_m' => 10_000, 'goal_time_sec' => 3_000]);
    $season = app(SeasonService::class)->ensureCurrent($user, Carbon::today());

    $this->actingAs($user)
        ->post('/race', racePayload(['name' => 'Renamed race', 'goal_time_sec' => 2_900]))
        ->assertRedirect();

    expect($old->fresh()->completed_at)->toBeNull()
        ->and($old->fresh()->goal_time_sec)->toBe(2_900)
        ->and($old->fresh()->name)->toBe('Renamed race')
        ->and(RaceGoal::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(app(SeasonService::class)->ensureCurrent($user, Carbon::today())->id)->toBe($season->id);
});

it('starts a new event, keeping the old one on record, when the intent is new', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $user = User::factory()->create();
    $old = RaceGoal::factory()->for($user)->create(['name' => 'Old race', 'race_date' => Carbon::today()->addWeeks(20)->toDateString()]);

    $this->actingAs($user)
        ->post('/race', racePayload(['name' => 'New race', 'intent' => 'new']))
        ->assertRedirect();

    $active = RaceGoal::query()->where('user_id', $user->id)->active()->get();
    expect($old->fresh()->completed_at)->not->toBeNull()
        ->and($active)->toHaveCount(1)
        ->and($active->first()->name)->toBe('New race')
        ->and(RaceGoal::query()->where('user_id', $user->id)->count())->toBe(2);

    Carbon::setTestNow();
});

it('rejects revising the current race into a different distance', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['distance_m' => 10_000]);

    $this->actingAs($user)
        ->post('/race', racePayload(['distance_m' => 21_097]))
        ->assertSessionHasErrors('distance_m');
});

it('does not rebuild the plan or add history when the same race is submitted again', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $race = RaceGoal::factory()->for($user)->create(['race_date' => now()->addWeeks(12)->toDateString(), 'distance_m' => 10_000, 'goal_time_sec' => 3_000, 'name' => 'Jakarta 10K']);

    $this->actingAs($user)->post('/race', racePayload())->assertRedirect();

    Bus::assertNothingDispatched();
    expect($race->changes()->count())->toBe(0)
        ->and(PlannedSession::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('shows the stated target beside the supported effort, the mode and the event history', function (): void {
    $user = User::factory()->create();
    PerformanceEvidence::query()->create([
        'user_id' => $user->id, 'activity_id' => Activity::factory()->for($user)->create()->id, 'kind' => 'test', 'distance_m' => 10_000, 'elapsed_time_sec' => 4_200,
        'performed_on' => Carbon::today()->subWeek(), 'confirmed_at' => now(),
    ]);
    $this->actingAs($user)->post('/race', racePayload(['goal_time_sec' => 3_000]))->assertRedirect();

    $this->actingAs($user)->get('/race')->assertInertia(fn (Assert $page) => $page
        ->where('race.goal_time_sec', 3_000)
        ->where('race.ambition.state', 'unsupported')
        ->where('race.ambition.target_pace_sec_per_km', 300)
        ->where('race.support.mode', 'road')
        ->where('race.history.0.kind', 'created')
        ->has('race.ambition.supported_time_sec'));
});

it('rejects an invalid submission and persists nothing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/race', racePayload(['distance_m' => 100]))
        ->assertSessionHasErrors('distance_m');

    expect(RaceGoal::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('shares the active race app-wide via the activeRace prop', function (): void {
    $user = User::factory()->create();
    RaceGoal::factory()->for($user)->create(['name' => 'Shared race', 'distance_m' => 5_000]);

    $this->actingAs($user)->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeRace.name', 'Shared race')
            ->where('activeRace.distance_m', 5_000));
});

/**
 * A race replaces the plan's whole structure — PhaseSchedule::forRace()
 * supersedes the self-scaled arc. Waiting for Monday trained the athlete
 * against an arc their own goal had superseded, while the flash message said
 * Temari would keep the plan honest against it.
 */
it('reshapes the plan the moment a race is set', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    $user = User::factory()->create();

    expect(PlannedSession::query()->where('user_id', $user->id)->count())->toBe(0);

    $this->actingAs($user)->post('/race', [
        'race_date' => Carbon::today()->addMonths(4)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 7_200,
        'name' => 'A half',
    ])->assertSessionHasNoErrors();

    expect(PlannedSession::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0);

    Carbon::setTestNow();
});

it('refuses the demo account a race save or clear, billing nothing and writing nothing', function (): void {
    Bus::fake();
    $demo = User::factory()->create(['is_demo' => true]);
    $race = RaceGoal::factory()->for($demo)->create(['name' => 'Seeded half']);

    $this->actingAs($demo)
        ->post('/race', racePayload(['name' => 'visit evil.example']), ['X-Inertia' => 'true'])
        ->assertRedirect()
        ->assertSessionHasErrors('demo');
    $this->actingAs($demo)->postJson('/race', racePayload(['name' => 'visit evil.example']))->assertForbidden();
    $this->actingAs($demo)->deleteJson('/race')->assertForbidden();

    Bus::assertNothingDispatched();
    expect(RaceGoal::query()->where('user_id', $demo->id)->sole()->name)->toBe('Seeded half')
        ->and($race->fresh()->completed_at)->toBeNull();
});

/**
 * `/race` had only GET and POST: a race could be superseded by another but
 * never simply called off, so a wrong date was stuck until it passed while the
 * plan kept building phases toward it.
 */
it('clears the active race, keeping it on record, and rebuilds the plan without it', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $user = User::factory()->create();
    $race = RaceGoal::query()->create([
        'user_id' => $user->id,
        'race_date' => Carbon::today()->addMonths(4)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 7_200,
        'name' => 'A half',
    ]);

    $this->actingAs($user)->delete('/race')->assertSessionHasNoErrors();

    expect($race->fresh()->completed_at)->not->toBeNull()
        ->and(RaceGoal::query()->where('user_id', $user->id)->active()->exists())->toBeFalse()
        ->and(PlannedSession::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0)
        ->and(RaceGoal::query()->where('user_id', $user->id)->count())->toBe(1);

    Carbon::setTestNow();
});

/**
 * #939: setting a race re-narrates only the season, never any day — a freshly
 * regenerated week has no run in it yet for a day's read to speak to.
 */
it('attributes a race save\'s re-narration to the athlete, so it re-arms the row\'s retry budget', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    $user = User::factory()->create();

    $this->actingAs($user)->post('/race', racePayload())->assertSessionHasNoErrors();

    Bus::assertDispatched(fn (AnalyzePlanSeasonVoiceJob $job): bool => $job->origin === AnalysisOrigin::User);

    Carbon::setTestNow();
});

it('attributes a cleared race\'s re-narration to the athlete', function (): void {
    Bus::fake();
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    $user = User::factory()->create();
    RaceGoal::query()->create([
        'user_id' => $user->id,
        'race_date' => Carbon::today()->addMonths(4)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 7_200,
        'name' => 'A half',
    ]);

    $this->actingAs($user)->delete('/race')->assertSessionHasNoErrors();

    Bus::assertDispatched(fn (AnalyzePlanSeasonVoiceJob $job): bool => $job->origin === AnalysisOrigin::User);

    Carbon::setTestNow();
});

it('does nothing when there is no race of its own to clear, never clearing another athlete\'s race', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $theirs = RaceGoal::query()->create([
        'user_id' => $other->id,
        'race_date' => Carbon::today()->addMonths(4)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 7_200,
        'name' => 'Theirs',
    ]);

    $this->actingAs($user)->delete('/race')->assertSessionHasNoErrors();

    expect($theirs->fresh()->completed_at)->toBeNull()
        ->and(PlannedSession::query()->where('user_id', $user->id)->count())->toBe(0);
});
