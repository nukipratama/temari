<?php

declare(strict_types=1);

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
    $this->post(route('rekap.bulanan.telegram', ['month' => '2026-06']))->assertRedirect(route('login'));
});

it('force-sends the push when the monthly recap is done', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create();
    $analysis = doneAnalysisFor(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-06', content: 'Bulan ini 120 km.');

    $this->actingAs($user)
        ->post(route('rekap.bulanan.telegram', ['month' => '2026-06']))
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
    $analysis = doneAnalysisFor(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-06', content: 'Bulan ini 120 km.');
    RateLimiter::hit(Cooldown::telegramKey($analysis->id), Cooldown::WINDOW_SECONDS);

    $this->actingAs($user)
        ->post(route('rekap.bulanan.telegram', ['month' => '2026-06']))
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('does not send and flashes info when the recap is not ready', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    doneAnalysisFor(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-06', done: false, content: 'Bulan ini 120 km.');

    $this->actingAs($user)
        ->post(route('rekap.bulanan.telegram', ['month' => '2026-06']))
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('does not send for a month the user has no recap for', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    doneAnalysisFor(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, '2026-06', content: 'Bulan ini 120 km.');

    $this->actingAs($user)
        ->post(route('rekap.bulanan.telegram', ['month' => '2026-05']))
        ->assertRedirect()
        ->assertSessionHas('info');

    Notification::assertNothingSent();
});

it('404s on a malformed month', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('rekap.bulanan.telegram', ['month' => 'juni-2026']))
        ->assertNotFound();

    Notification::assertNothingSent();
});
