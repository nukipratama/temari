<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Facades\Queue;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Models\RaceGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('regenerates the plan for every user', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->artisan('plan:regenerate')
        ->expectsOutputToContain('Regenerated the plan for 2 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $a->id)->exists())->toBeTrue()
        ->and(PlannedSession::query()->where('user_id', $b->id)->exists())->toBeTrue();
});

it('limits to a single user via --user', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->artisan("plan:regenerate --user={$a->id}")
        ->expectsOutputToContain('Regenerated the plan for 1 user(s).')
        ->assertSuccessful();

    expect(PlannedSession::query()->where('user_id', $a->id)->exists())->toBeTrue()
        ->and(PlannedSession::query()->where('user_id', $b->id)->exists())->toBeFalse();
});

/**
 * The crash this branch exists to stop: a race whose day had passed made
 * PhaseSchedule::forRace() count a negative number of weeks and array_fill()
 * throw. Because the command looped without a guard, that took every athlete
 * after the thrower in the same run with it — proven with a bystander before
 * fixing.
 *
 * The per-user try/catch that now contains such a failure has no test of its
 * own: every class in the plan chain is `final`, so no throw can be injected,
 * and no reachable data corruption I could construct still makes regenerate
 * throw. That robustness is why. The guard below covers the one cause that
 * was real.
 */
it('regenerates a user whose race day has passed instead of throwing', function (): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $user = User::factory()->create();
    RaceGoal::query()->create([
        'user_id' => $user->id,
        'race_date' => Carbon::today()->subDays(21)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 7_200,
        'name' => 'already run',
    ]);

    $this->artisan('plan:regenerate', ['--user' => $user->id])->assertSuccessful();

    Carbon::setTestNow();
});

/**
 * #939: the sweep only ever narrates the season now — a freshly regenerated
 * week has no run in it yet for a day's own read to speak to. Season rows are
 * keyed by the season's own id, not the user's, so the season has to be
 * resolved first.
 */
function seasonVoiceRowsFor(User $user): int
{
    $seasonId = Season::query()->where('user_id', $user->id)->value('id');
    if ($seasonId === null) {
        return 0;
    }

    return Analysis::query()
        ->where('subject_type', Season::class)
        ->where('subject_id', $seasonId)
        ->where('analysis_type', AnalysisType::PlanSeasonVoice)
        ->count();
}

it('regenerates the plan for a dormant athlete but narrates only the active one', function (): void {
    Queue::fake();
    $active = User::factory()->seenToday()->create();
    $dormant = User::factory()->create(['last_seen_at' => Carbon::today()->subDays(30)]);

    $this->artisan('plan:regenerate')
        ->expectsOutputToContain('Regenerated the plan for 2 user(s).')
        ->assertSuccessful();

    expect(seasonVoiceRowsFor($active))->toBeGreaterThan(0)
        ->and(seasonVoiceRowsFor($dormant))->toBe(0)
        ->and(Analysis::query()->where('subject_id', $active->id)->where('analysis_type', AnalysisType::PlanDayVoice)->count())->toBe(0)
        ->and(PlannedSession::query()->where('user_id', $dormant->id)->exists())->toBeTrue();
});

it('never narrates the demo plan even when the demo was seen today', function (): void {
    Queue::fake();
    $demo = User::factory()->demo()->seenToday()->create();

    $this->artisan('plan:regenerate')->assertSuccessful();

    expect(seasonVoiceRowsFor($demo))->toBe(0);
});
