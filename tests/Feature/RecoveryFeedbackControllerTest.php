<?php

declare(strict_types=1);

use App\Enums\RecoveryConcernLevel;
use App\Enums\SleepQuality;
use App\Jobs\AI\AnalyzePlanClampVoiceJob;
use App\Models\PlannedSession;
use App\Models\RecoveryFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('stores recovery feedback for the authenticated user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('recovery.feedback.store'), [
            'sleep_quality' => 'good',
            'user_id' => $otherUser->id,
        ])
        ->assertCreated()
        ->assertJsonPath('date', today()->toDateString())
        ->assertJsonPath('sleep_quality', SleepQuality::Good->value)
        ->assertJsonPath('fatigue', null)
        ->assertJsonPath('soreness', null)
        ->assertJsonPath('concerning_pain', null)
        ->assertJsonPath('illness', null)
        ->assertJsonStructure(['updated_at']);

    expect(RecoveryFeedback::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(RecoveryFeedback::query()->where('user_id', $otherUser->id)->count())->toBe(0);
});

it('reassesses the current uncompleted session immediately when feedback changes', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $session = PlannedSession::factory()->for($user)->create([
        'date' => today()->toDateString(),
        'session_type' => 'interval',
    ]);

    $this->actingAs($user)->postJson(route('recovery.feedback.store'), [
        'concerning_pain' => true,
    ])->assertCreated();

    expect($session->fresh()->rest_clamped_at)->not->toBeNull()
        ->and($session->fresh()->readiness_assessment['reasons'])->toContain('concerning_pain_reported');

    $this->actingAs($user)->postJson(route('recovery.feedback.store'), [
        'concerning_pain' => false,
    ])->assertOk();

    expect($session->fresh()->rest_clamped_at)->toBeNull()
        ->and($session->fresh()->clamped_km)->toBeNull()
        ->and($session->fresh()->readiness_assessment)->toBeNull();
    Bus::assertNotDispatched(AnalyzePlanClampVoiceJob::class);
});

it('creates an all-unknown row without requiring a run', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('recovery.feedback.store'))
        ->assertCreated()
        ->assertJsonPath('sleep_quality', null)
        ->assertJsonPath('fatigue', null)
        ->assertJsonPath('soreness', null)
        ->assertJsonPath('concerning_pain', null)
        ->assertJsonPath('illness', null);

    expect(RecoveryFeedback::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('preserves unspecified fields and distinguishes null from false on a partial update', function (): void {
    $user = User::factory()->create();
    $date = today()->subDay()->toDateString();

    $this->actingAs($user)->postJson(route('recovery.feedback.store'), [
        'date' => $date,
        'sleep_quality' => 'poor',
        'fatigue' => 'severe',
        'soreness' => 'mild',
        'concerning_pain' => true,
        'illness' => true,
    ])->assertCreated();

    $this->actingAs($user)->postJson(route('recovery.feedback.store'), [
        'date' => $date,
        'fatigue' => RecoveryConcernLevel::Moderate->value,
    ])->assertOk();

    $this->actingAs($user)->postJson(route('recovery.feedback.store'), [
        'date' => $date,
        'sleep_quality' => null,
        'fatigue' => null,
        'concerning_pain' => false,
    ])->assertOk()
        ->assertJsonPath('sleep_quality', null)
        ->assertJsonPath('concerning_pain', false);

    $feedback = RecoveryFeedback::query()->where('user_id', $user->id)->firstOrFail();

    expect(RecoveryFeedback::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($feedback->sleep_quality)->toBeNull()
        ->and($feedback->fatigue)->toBeNull()
        ->and($feedback->soreness)->toBe(RecoveryConcernLevel::Mild)
        ->and($feedback->concerning_pain)->toBeFalse()
        ->and($feedback->illness)->toBeTrue();
});

it('accepts past dates and rejects future dates', function (): void {
    $user = User::factory()->create();
    $pastDate = today()->subDays(5)->toDateString();
    $futureDate = today()->addDay()->toDateString();

    $this->actingAs($user)
        ->postJson(route('recovery.feedback.store'), ['date' => $pastDate])
        ->assertCreated()
        ->assertJsonPath('date', $pastDate);

    $this->actingAs($user)
        ->postJson(route('recovery.feedback.store'), ['date' => $futureDate])
        ->assertInvalid(['date']);
});

it('validates enum and boolean values', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('recovery.feedback.store'), ['sleep_quality' => 'excellent'])
        ->assertInvalid(['sleep_quality']);

    $this->actingAs($user)
        ->postJson(route('recovery.feedback.store'), ['fatigue' => 'extreme'])
        ->assertInvalid(['fatigue']);

    $this->actingAs($user)
        ->postJson(route('recovery.feedback.store'), ['illness' => 'unknown'])
        ->assertInvalid(['illness']);

    expect(RecoveryFeedback::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('rejects guests and demo-account writes', function (): void {
    $this->postJson(route('recovery.feedback.store'))->assertUnauthorized();

    $demo = User::factory()->create(['is_demo' => true]);

    $this->actingAs($demo)
        ->postJson(route('recovery.feedback.store'), ['sleep_quality' => 'good'])
        ->assertForbidden();

    expect(RecoveryFeedback::query()->where('user_id', $demo->id)->exists())->toBeFalse();
});
