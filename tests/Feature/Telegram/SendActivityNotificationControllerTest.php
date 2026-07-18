<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\AnalysisReadyNotification;
use App\Services\AI\AnalysisType;
use App\Support\Cooldown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-bot-token']);
});

it('requires authentication', function (): void {
    $activity = Activity::factory()->create();

    $this->post(route('aktivitas.telegram', $activity))->assertRedirect(route('login'));
});

it('force-sends the push when the post-run speech is done', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_post_run' => false]);
    $activity = Activity::factory()->for($user)->create();
    $analysis = doneAnalysisFor(Activity::class, $activity->id, AnalysisType::PostRunSpeech, content: 'Mantap!');

    $this->actingAs($user)
        ->post(route('aktivitas.telegram', $activity))
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentTo(
        $user,
        AnalysisReadyNotification::class,
        fn (AnalysisReadyNotification $notification): bool => $notification->analysis->id === $analysis->id && $notification->force === true,
    );
});

it('does not re-send and flashes info while the send cooldown is active', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $analysis = doneAnalysisFor(Activity::class, $activity->id, AnalysisType::PostRunSpeech, content: 'Mantap!');
    RateLimiter::hit(Cooldown::telegramKey($analysis->id), Cooldown::WINDOW_SECONDS);

    $this->actingAs($user)
        ->post(route('aktivitas.telegram', $activity))
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('does not send and flashes info when the narration is not ready', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    doneAnalysisFor(Activity::class, $activity->id, AnalysisType::PostRunSpeech, done: false);

    $this->actingAs($user)
        ->post(route('aktivitas.telegram', $activity))
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('404s when the activity belongs to another user', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $activity = Activity::factory()->for($owner)->create();

    $this->actingAs($other)
        ->post(route('aktivitas.telegram', $activity))
        ->assertNotFound();

    Notification::assertNothingSent();
});
