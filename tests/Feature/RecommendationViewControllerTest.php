<?php

declare(strict_types=1);

use App\Models\PlannedSession;
use App\Models\RecommendationRevision;
use App\Models\RecommendationView;
use App\Models\User;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\RecommendationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-12 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

function viewedPlanAthlete(): User
{
    $user = User::factory()->create();
    app(Periodizer::class)->regenerate($user, Carbon::today());

    return $user;
}

function currentRecommendationToken(User $user, string $date): string
{
    $token = app(PlanPageAssembler::class)->recommendationToken($user, Carbon::today(), $date);

    return is_string($token) ? $token : throw new InvalidArgumentException("No recommendation token for {$date}.");
}

it('persists the current snapshot it was shown', function (): void {
    $user = viewedPlanAthlete();
    $tomorrow = Carbon::today()->addDay()->toDateString();
    $token = currentRecommendationToken($user, $tomorrow);
    $advice = arrayOf(json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR));

    $this->actingAs($user)->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => (string) Str::uuid()])->assertNoContent();

    expect(RecommendationRevision::query()->sole()->effective)->toEqual($advice['effective'])
        ->and(RecommendationView::query()->count())->toBe(1);
});

it('accepts the token Home shows for today', function (): void {
    $user = viewedPlanAthlete();
    $days = arrayOf($this->actingAs($user)->get('/')->assertSuccessful()->inertiaProps('weekPlan.days'));
    $today = collect($days)->map(fn (mixed $day): array => arrayOf($day))->firstWhere('date', Carbon::today()->toDateString());
    $token = arrayOf($today)['recommendation_token'];

    $this->actingAs($user)->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => (string) Str::uuid()])->assertNoContent();

    expect(RecommendationView::query()->count())->toBe(1);
});

it('refuses advice a plan edit has since replaced, recording nothing', function (): void {
    $user = viewedPlanAthlete();
    $tomorrow = Carbon::today()->addDay()->toDateString();
    $stale = currentRecommendationToken($user, $tomorrow);
    PlannedSession::query()->where('user_id', $user->id)->whereDate('date', $tomorrow)->sole()->update(['skipped' => true]);

    $this->actingAs($user)->postJson(route('plan.recommendations.shown'), ['token' => $stale, 'observation_id' => (string) Str::uuid()])->assertConflict();

    expect(RecommendationRevision::query()->count())->toBe(0)
        ->and(RecommendationView::query()->count())->toBe(0);
});

it('refuses advice for a day the plan does not carry', function (): void {
    $user = viewedPlanAthlete();
    $token = app(RecommendationHistory::class)->token($user->id, Carbon::today()->addYears(2)->toDateString(), ['session_type' => 'tempo'], ['session_type' => 'easy']);

    $this->actingAs($user)->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => (string) Str::uuid()])->assertConflict();

    expect(RecommendationView::query()->count())->toBe(0);
});

it('rejects another athletes token, tampering and invalid receipts', function (): void {
    $owner = User::factory()->create();
    $token = app(RecommendationHistory::class)->token($owner->id, '2026-10-01', [], []);
    $this->actingAs(User::factory()->create())->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => (string) Str::uuid()])->assertForbidden();
    $this->actingAs($owner)->postJson(route('plan.recommendations.shown'), ['token' => 'tampered', 'observation_id' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => 'not-uuid'])->assertUnprocessable();
    expect(RecommendationRevision::query()->count())->toBe(0);
});
