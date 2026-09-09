<?php

declare(strict_types=1);

use App\Enums\FeedbackReason;
use App\Enums\FeedbackSubject;
use App\Models\Feedback;
use App\Models\PlannedSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function flagPayload(PlannedSession $day, ?string $note = null): array
{
    return array_filter([
        'subject_type' => 'plan_day',
        'subject_id' => $day->id,
        'reason' => 'too_hard',
        'note' => $note,
    ], fn (mixed $value): bool => $value !== null);
}

it('requires an authenticated user', function (): void {
    $this->post(route('feedback.store'), ['subject_type' => 'plan_day', 'subject_id' => 1, 'reason' => 'too_hard'])
        ->assertRedirect(route('login'));
});

it('records a flag with a note against the day it is about', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $day = PlannedSession::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('plan'))
        ->post(route('feedback.store'), flagPayload($day, '  this was way too long  '))
        ->assertRedirect(route('plan'));

    $feedback = Feedback::query()->sole();

    expect($feedback->user_id)->toBe($user->id)
        ->and($feedback->subject_type)->toBe(FeedbackSubject::PlanDay)
        ->and($feedback->subject_id)->toBe($day->id)
        ->and($feedback->reason)->toBe(FeedbackReason::TooHard)
        ->and($feedback->note)->toBe('this was way too long');
});

it('records a flag with no note at all', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $day = PlannedSession::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('plan'))
        ->post(route('feedback.store'), flagPayload($day, '   '))
        ->assertRedirect(route('plan'));

    expect(Feedback::query()->sole()->note)->toBeNull();
});

it('rejects an unknown subject type', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $this->actingAs($user)
        ->from(route('plan'))
        ->post(route('feedback.store'), ['subject_type' => 'weather', 'subject_id' => 1, 'reason' => 'too_hard'])
        ->assertSessionHasErrors('subject_type');

    expect(Feedback::query()->count())->toBe(0);
});

it('rejects a note longer than the column', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $day = PlannedSession::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('plan'))
        ->post(route('feedback.store'), flagPayload($day, str_repeat('a', Feedback::MAX_NOTE_LENGTH + 1)))
        ->assertSessionHasErrors('note');

    expect(Feedback::query()->count())->toBe(0);
});

it('forbids flagging a plan day that belongs to someone else', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $day = PlannedSession::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)
        ->post(route('feedback.store'), flagPayload($day))
        ->assertForbidden();

    expect(Feedback::query()->count())->toBe(0);
});

it('blocks the shared demo account', function (): void {
    $demo = User::factory()->create(['onboarded_at' => now(), 'is_demo' => true]);
    $day = PlannedSession::factory()->for($demo)->create();

    $this->actingAs($demo)
        ->postJson(route('feedback.store'), flagPayload($day))
        ->assertForbidden();

    expect(Feedback::query()->count())->toBe(0);
});

it('throttles a runner hammering the endpoint', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $day = PlannedSession::factory()->for($user)->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)
            ->from(route('plan'))
            ->post(route('feedback.store'), flagPayload($day))
            ->assertRedirect(route('plan'));
    }

    $this->actingAs($user)
        ->post(route('feedback.store'), flagPayload($day))
        ->assertStatus(429);
});

it('rejects a reason that does not belong to the subject', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $day = PlannedSession::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('plan'))
        ->post(route('feedback.store'), [...flagPayload($day), 'reason' => 'tone_off'])
        ->assertSessionHasErrors('reason');

    expect(Feedback::query()->count())->toBe(0);
});

it('rejects a flag with no reason at all', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $day = PlannedSession::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('plan'))
        ->post(route('feedback.store'), ['subject_type' => 'plan_day', 'subject_id' => $day->id])
        ->assertSessionHasErrors('reason');

    expect(Feedback::query()->count())->toBe(0);
});

it('lands a second flag on the row already there', function (): void {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $day = PlannedSession::factory()->for($user)->create();

    $this->actingAs($user)
        ->from(route('plan'))
        ->post(route('feedback.store'), flagPayload($day, 'first'))
        ->assertRedirect(route('plan'));

    $this->actingAs($user)
        ->from(route('plan'))
        ->post(route('feedback.store'), [...flagPayload($day, 'second'), 'reason' => 'too_easy'])
        ->assertRedirect(route('plan'));

    $feedback = Feedback::query()->sole();

    expect($feedback->reason)->toBe(FeedbackReason::TooHard)
        ->and($feedback->note)->toBe('first');
});
