<?php

declare(strict_types=1);

use App\Models\RecommendationRevision;
use App\Models\RecommendationView;
use App\Models\User;
use App\Services\Run\Plan\RecommendationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists the delivered snapshot without needing a surviving daily row', function (): void {
    $user = User::factory()->create();
    $token = app(RecommendationHistory::class)->token($user->id, '2026-10-01', ['session_type' => 'tempo'], ['session_type' => 'easy']);
    expect(RecommendationRevision::query()->count())->toBe(0);
    $this->actingAs($user)->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => (string) Str::uuid()])->assertNoContent();
    expect(RecommendationRevision::query()->sole()->effective)->toBe(['session_type' => 'easy'])
        ->and(RecommendationView::query()->count())->toBe(1);
});

it('rejects another athletes token, tampering and invalid receipts', function (): void {
    $owner = User::factory()->create();
    $token = app(RecommendationHistory::class)->token($owner->id, '2026-10-01', [], []);
    $this->actingAs(User::factory()->create())->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => (string) Str::uuid()])->assertForbidden();
    $this->actingAs($owner)->postJson(route('plan.recommendations.shown'), ['token' => 'tampered', 'observation_id' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson(route('plan.recommendations.shown'), ['token' => $token, 'observation_id' => 'not-uuid'])->assertUnprocessable();
    expect(RecommendationRevision::query()->count())->toBe(0);
});
