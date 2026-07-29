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
        ->and($user->notificationPreference->notifications_enabled)->toBeFalse();
});
