<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Models\RecommendationRevision;
use App\Models\RecommendationView;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\RecommendationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('accepts a purposeful test and rejects another athletes activity', function (): void {
    $user = User::factory()->create();
    $payload = ['kind' => 'test', 'distance_m' => 5000, 'elapsed_time_sec' => 1500, 'performed_on' => '2026-10-01'];
    $this->actingAs($user)->postJson(route('fitness.evidence.store'), $payload)->assertCreated();
    expect(PerformanceEvidence::query()->sole()->user_id)->toBe($user->id);
    $this->postJson(route('fitness.evidence.store'), $payload + ['activity_id' => Activity::factory()->create()->id])->assertUnprocessable();
});

it('rejects future performances and distances outside the qualifying range', function (): void {
    $user = User::factory()->create();
    $payload = ['kind' => 'race', 'distance_m' => 5000, 'elapsed_time_sec' => 1500, 'performed_on' => '2026-10-02'];

    $this->actingAs($user)->postJson(route('fitness.evidence.store'), $payload)->assertUnprocessable();
    $this->postJson(route('fitness.evidence.store'), [...$payload, 'performed_on' => '2026-10-01', 'distance_m' => 500])->assertUnprocessable();
    $this->postJson(route('fitness.evidence.store'), [...$payload, 'performed_on' => '2026-10-01', 'distance_m' => 50_000])->assertUnprocessable();
    expect(PerformanceEvidence::query()->count())->toBe(0);
});

it('treats activity confirmation as idempotent and preserves the first confirmed details', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $payload = [
        'kind' => 'race', 'distance_m' => 5000, 'elapsed_time_sec' => 1500,
        'performed_on' => '2026-09-20', 'activity_id' => $activity->id,
    ];

    $this->actingAs($user)->postJson(route('fitness.evidence.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('fitness.vdot_source.confidence', 'confirmed')
        ->assertJsonPath('fitness.vdot_source.evidence_kind', 'race')
        ->assertJsonPath('fitness.vdot_source.distance_m', 5000)
        ->assertJsonPath('plan_updated', false);
    $first = PerformanceEvidence::query()->sole();
    $confirmedAt = $first->confirmed_at->toDateTimeString();

    $this->postJson(route('fitness.evidence.store'), [...$payload, 'elapsed_time_sec' => 1700, 'performed_on' => '2026-09-21'])
        ->assertOk()
        ->assertJsonPath('id', $first->id);

    $first->refresh();
    expect(PerformanceEvidence::query()->count())->toBe(1)
        ->and($first->elapsed_time_sec)->toBe(1500)
        ->and($first->performed_on->toDateString())->toBe('2026-09-20')
        ->and($first->confirmed_at->toDateTimeString())->toBe($confirmedAt);
});

it('regenerates an existing plan when a confirmed result changes target paces and keeps shown history', function (): void {
    $user = User::factory()->create();
    foreach (range(0, 3) as $week) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($week)->toDateString(),
            'runs' => 4,
            'distance_km' => 30,
        ]);
    }
    $date = Carbon::today()->addDay()->toDateString();
    PlannedSession::factory()->for($user)->create(['date' => $date]);
    $history = app(RecommendationHistory::class);
    $revision = $history->record($user->id, $date, ['pace' => 330], ['pace' => 330]);
    $history->shown($revision, 'fitness-evidence-before-plan-update');

    $this->actingAs($user)->postJson(route('fitness.evidence.store'), [
        'kind' => 'test', 'distance_m' => 5000, 'elapsed_time_sec' => 1500,
        'performed_on' => Carbon::today()->subWeek()->toDateString(),
    ])->assertCreated()
        ->assertJsonPath('plan_updated', true)
        ->assertJsonPath('fitness.vdot_source.confidence', 'confirmed')
        ->assertJsonPath('fitness.vdot_source.evidence_kind', 'test')
        ->assertJsonPath('fitness.vdot_source.distance_m', 5000);

    expect(RecommendationRevision::query()->count())->toBe(1)
        ->and(RecommendationView::query()->count())->toBe(1)
        ->and(PerformanceEvidence::query()->count())->toBe(1)
        ->and(Analysis::query()->count())->toBe(0);
});
