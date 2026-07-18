<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use App\Models\WeeklySnapshot;
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
    $snapshot = WeeklySnapshot::factory()->create();

    $this->post(route('rekap.mingguan.telegram', $snapshot))->assertRedirect(route('login'));
});

it('force-sends the push when the weekly recap is done', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => false]);
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    $analysis = doneAnalysisFor(WeeklySnapshot::class, $snapshot->id, AnalysisType::WeeklyRecap, content: 'Minggu ini 28 km.');

    $this->actingAs($user)
        ->post(route('rekap.mingguan.telegram', $snapshot))
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
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    $analysis = doneAnalysisFor(WeeklySnapshot::class, $snapshot->id, AnalysisType::WeeklyRecap, content: 'Minggu ini 28 km.');
    RateLimiter::hit(Cooldown::telegramKey($analysis->id), Cooldown::WINDOW_SECONDS);

    $this->actingAs($user)
        ->post(route('rekap.mingguan.telegram', $snapshot))
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('does not send and flashes info when the recap is not ready', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($user)->create();
    doneAnalysisFor(WeeklySnapshot::class, $snapshot->id, AnalysisType::WeeklyRecap, done: false, content: 'Minggu ini 28 km.');

    $this->actingAs($user)
        ->post(route('rekap.mingguan.telegram', $snapshot))
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('404s when the snapshot belongs to another user', function (): void {
    Notification::fake();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $snapshot = WeeklySnapshot::factory()->for($owner)->create();

    $this->actingAs($other)
        ->post(route('rekap.mingguan.telegram', $snapshot))
        ->assertNotFound();

    Notification::assertNothingSent();
});
