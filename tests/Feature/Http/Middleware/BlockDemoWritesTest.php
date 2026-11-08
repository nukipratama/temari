<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\InboxNotification;
use App\Models\PersonalRecord;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\RecommendationView;
use App\Models\RecordStamp;
use App\Models\RunnerProfile;
use App\Models\TelegramConnection;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('blocks a demo user from an Inertia notification-preference write with a flash error', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->withHeader('X-Inertia', 'true')
        ->patch('/profile/notifications', [
            'notifications_enabled' => false,
            'telegram_enabled' => true,
            'push_enabled' => true,
            'briefing_push_enabled' => true,
            'briefing_push_minute' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['demo' => 'The demo account is read-only. Nothing here can be changed.']);

    expect($user->notificationPreference()->exists())->toBeFalse();
});

it('returns a JSON 403 to a plain fetch on the notification test endpoint from the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson('/profile/notifications/test')
        ->assertStatus(403)
        ->assertJson(['message' => 'The demo account is read-only. Nothing here can be changed.']);
});

it('does not block a normal user from the same notification-preference write', function (): void {
    $user = User::factory()->create(['is_demo' => false]);

    $this->actingAs($user)
        ->patch('/profile/notifications', [
            'notifications_enabled' => false,
            'telegram_enabled' => true,
            'push_enabled' => true,
            'briefing_push_enabled' => true,
            'briefing_push_minute' => null,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    expect(nonNull($user->notificationPreference)->notifications_enabled)->toBeFalse();
});

dataset('demoWriteRoutes', [
    'POST /plan/regenerate' => [
        fn (User $user): null => null,
        fn ($test, User $user, mixed $context) => $test->post('/plan/regenerate'),
        fn (User $user, mixed $context): int => PlannedSession::query()->where('user_id', $user->id)->count(),
        true,
    ],
    'PATCH /plan/sessions/{plannedSession}' => [
        fn (User $user): PlannedSession => PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->addDay()->toDateString(),
            'session_type' => 'tempo',
            'skipped' => false,
        ]),
        fn ($test, User $user, PlannedSession $session) => $test->patch("/plan/sessions/{$session->id}", ['skipped' => true]),
        fn (User $user, PlannedSession $session): bool => $session->refresh()->skipped,
        true,
    ],
    'PATCH /settings/zones' => [
        fn (User $user): null => null,
        fn ($test, User $user, mixed $context) => $test->patch('/settings/zones', [
            'max_hr' => 190,
            'resting_hr' => 50,
            'zones' => [
                ['lo' => 120, 'hi' => 140],
                ['lo' => 140, 'hi' => 158],
                ['lo' => 158, 'hi' => 172],
                ['lo' => 172, 'hi' => 184],
                ['lo' => 184, 'hi' => 999],
            ],
        ]),
        fn (User $user, mixed $context): int => RunnerProfile::query()->where('user_id', $user->id)->count(),
        true,
    ],
    'DELETE /settings/zones' => [
        fn (User $user): RunnerProfile => RunnerProfile::factory()->for($user)->create(['source' => 'manual']),
        fn ($test, User $user, mixed $context) => $test->delete('/settings/zones'),
        fn (User $user, mixed $context): int => RunnerProfile::query()->where('user_id', $user->id)->count(),
        true,
    ],
    'PATCH /settings/training-preferences' => [
        fn (User $user): null => null,
        fn ($test, User $user, mixed $context) => $test->patch('/settings/training-preferences', ['sessions_per_week' => 5]),
        fn (User $user, mixed $context): ?int => TrainingPreference::query()->where('user_id', $user->id)->first()?->sessions_per_week,
        true,
    ],
    'PATCH /activities/{activity}/effort' => [
        function (User $user): ActivityDetail {
            $activity = Activity::factory()->for($user)->analyzed()->create();

            return ActivityDetail::factory()->for($activity)->create(['perceived_effort' => null]);
        },
        fn ($test, User $user, ActivityDetail $detail) => $test->patch("/activities/{$detail->activity_id}/effort", ['score' => 7]),
        fn (User $user, ActivityDetail $detail): ?int => $detail->refresh()->perceived_effort,
        true,
    ],
    'DELETE /activities/{activity}/effort' => [
        function (User $user): ActivityDetail {
            $activity = Activity::factory()->for($user)->analyzed()->create();

            return ActivityDetail::factory()->for($activity)->create(['perceived_effort' => 5, 'perceived_effort_at' => now()]);
        },
        fn ($test, User $user, ActivityDetail $detail) => $test->delete("/activities/{$detail->activity_id}/effort"),
        fn (User $user, ActivityDetail $detail): ?int => $detail->refresh()->perceived_effort,
        true,
    ],
    'POST /onboarding' => [
        function (User $user): null {
            $user->forceFill(['onboarded_at' => null])->save();

            return null;
        },
        fn ($test, User $user, mixed $context) => $test->post('/onboarding', [
            'race_date' => now()->addWeeks(12)->toDateString(),
            'distance_m' => 10_000,
            'goal_time_sec' => 3_000,
            'name' => 'Jakarta 10K',
        ]),
        fn (User $user, mixed $context): array => [$user->refresh()->onboarded_at?->toIso8601String(), RaceGoal::query()->where('user_id', $user->id)->count()],
        true,
    ],
    'POST /api/record-stamps' => [
        function (User $user): null {
            PersonalRecord::factory()->forActivity(Activity::factory()->for($user)->create())->create(['category' => '10km']);

            return null;
        },
        fn ($test, User $user, mixed $context) => $test->postJson('/api/record-stamps', ['record_key' => '10km']),
        fn (User $user, mixed $context): int => RecordStamp::query()->where('user_id', $user->id)->count(),
        false,
    ],
    'POST /api/notifications/{notification}/read' => [
        fn (User $user): InboxNotification => InboxNotification::factory()->for($user)->create(['read_at' => null]),
        fn ($test, User $user, InboxNotification $row) => $test->postJson("/api/notifications/{$row->id}/read"),
        fn (User $user, InboxNotification $row): bool => $row->refresh()->read_at !== null,
        false,
    ],
    'POST /api/notifications/read-all' => [
        fn (User $user): InboxNotification => InboxNotification::factory()->for($user)->create(['read_at' => null]),
        fn ($test, User $user, InboxNotification $row) => $test->postJson('/api/notifications/read-all'),
        fn (User $user, InboxNotification $row): bool => $row->refresh()->read_at !== null,
        false,
    ],
    'POST /plan/recommendations/shown' => [
        function (User $user): mixed {
            app(Periodizer::class)->regenerate($user);

            return test()->actingAs($user)->getJson(route('plan.days.recommendation', Carbon::today()->addDay()->toDateString()))->json('token');
        },
        fn ($test, User $user, mixed $token) => $test->postJson('/plan/recommendations/shown', ['token' => $token, 'observation_id' => (string) Str::uuid()]),
        fn (User $user, mixed $token): int => RecommendationView::query()->count(),
        false,
    ],
]);

it('keeps the demo account read-only on the write route while a regular user still succeeds', function (Closure $arrange, Closure $send, Closure $state, bool $inertia): void {
    Queue::fake();
    Bus::fake();

    $demo = User::factory()->demo()->create();
    $demoContext = $arrange($demo);
    $before = $state($demo, $demoContext);

    $this->actingAs($demo)->withHeaders($inertia ? ['X-Inertia' => 'true'] : []);
    $blocked = $send($this, $demo, $demoContext);

    $inertia
        ? $blocked->assertRedirect()->assertSessionHasErrors(['demo' => 'The demo account is read-only. Nothing here can be changed.'])
        : $blocked->assertForbidden()->assertJson(['message' => 'The demo account is read-only. Nothing here can be changed.']);
    expect($state($demo, $demoContext))->toBe($before);

    $regular = User::factory()->create();
    $regularContext = $arrange($regular);
    $regularBefore = $state($regular, $regularContext);

    $this->actingAs($regular)->withHeaders($inertia ? ['X-Inertia' => 'true'] : []);
    $allowed = $send($this, $regular, $regularContext)->assertSessionDoesntHaveErrors();

    $inertia ? $allowed->assertRedirect() : $allowed->assertSuccessful();

    expect($state($regular, $regularContext))->not->toBe($regularBefore);
})->with('demoWriteRoutes');

it('does not block a GET read from the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)->get('/profile')->assertSuccessful();
});

it('still lets the demo user log out', function (): void {
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});

it('still lets the demo user trigger "Reread", served rule-based so it never bills', function (): void {
    Bus::fake();
    $user = User::factory()->create(['is_demo' => true]);

    $this->actingAs($user)
        ->postJson("/api/analyses/briefing_mascot_voice/{$user->id}/trigger?discriminator=2026-05-18")
        ->assertSuccessful()
        ->assertJson(['status' => 'done']);

    Bus::assertNothingDispatched();
});
