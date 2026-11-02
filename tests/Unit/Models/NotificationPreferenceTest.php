<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $preference = NotificationPreference::factory()->create();

    expect($preference->user)->toBeInstanceOf(User::class);
});

it('casts both axes to booleans', function (): void {
    $preference = NotificationPreference::factory()->make([
        'notifications_enabled' => 0,
        'telegram_enabled' => 1,
        'push_enabled' => 0,
    ]);

    expect($preference->notifications_enabled)->toBeFalse()
        ->and($preference->telegram_enabled)->toBeTrue()
        ->and($preference->push_enabled)->toBeFalse();
});

it('is reachable as a hasOne from the user', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['notifications_enabled' => false]);

    expect($user->notificationPreference)->toBeInstanceOf(NotificationPreference::class)
        ->and($user->notificationPreference?->notifications_enabled)->toBeFalse();
});

it('casts the briefing push switch to a boolean and its send time to an integer minute', function (): void {
    $preference = NotificationPreference::factory()->make([
        'briefing_push_enabled' => 0,
        'briefing_push_minute' => '330',
    ]);

    expect($preference->briefing_push_enabled)->toBeFalse()
        ->and($preference->briefing_push_minute)->toBe(330);
});

it('defaults to the briefing push on at the automatic time', function (): void {
    $preference = NotificationPreference::query()->create(['user_id' => User::factory()->create()->id]);

    expect($preference->refresh()->briefing_push_enabled)->toBeTrue()
        ->and($preference->briefing_push_minute)->toBeNull();
});
