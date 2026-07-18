<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\StreakReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function streakVia(User $user): array
{
    return new StreakReminderNotification(3)->via($user);
}

it('routes to Telegram for a connected, weekly-opted-in user', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => true]);

    expect(streakVia($user))->toBe([TelegramChannel::class]);
});

it('routes nowhere for the demo user', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => true]);

    expect(streakVia($user))->toBe([]);
});

it('routes nowhere without a connection', function (): void {
    expect(streakVia(User::factory()->create()))->toBe([]);
});

it('routes nowhere over a revoked connection', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->revoked()->create(['notify_weekly_recap' => true]);

    expect(streakVia($user))->toBe([]);
});

it('routes nowhere when weekly recap is opted out', function (): void {
    $user = User::factory()->create();
    TelegramConnection::factory()->for($user)->create(['notify_weekly_recap' => false]);

    expect(streakVia($user))->toBe([]);
});

it('builds a keyless message naming the streak length', function (): void {
    $message = new StreakReminderNotification(3)->toTelegram(User::factory()->create());

    expect($message->text)->toContain('3 minggu')
        ->and($message->text)->toContain('Buka Temari')
        ->and($message->deliveryKey)->toBeNull();
});
