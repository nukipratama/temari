<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a preference row on the first write', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile/notifications', [
            'notifications_enabled' => false,
            'telegram_enabled' => true,
            'push_enabled' => true,
            'briefing_push_enabled' => true,
            'briefing_push_minute' => null,
        ])
        ->assertRedirect();

    $preference = nonNull($user->notificationPreference()->first());
    expect($preference->notifications_enabled)->toBeFalse()
        ->and($preference->telegram_enabled)->toBeTrue();
});

it('updates the existing preference row without creating a second', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create([
        'notifications_enabled' => true,
        'telegram_enabled' => true,
        'push_enabled' => true,
    ]);

    $this->actingAs($user)
        ->patch('/profile/notifications', [
            'notifications_enabled' => false,
            'telegram_enabled' => true,
            'push_enabled' => true,
            'briefing_push_enabled' => true,
            'briefing_push_minute' => null,
        ])
        ->assertRedirect();

    expect($user->notificationPreference()->count())->toBe(1)
        ->and(nonNull($user->notificationPreference()->first())->notifications_enabled)->toBeFalse();
});

it('persists the channel mutes alongside the master switch', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile/notifications', [
            'notifications_enabled' => true,
            'telegram_enabled' => false,
            'push_enabled' => true,
            'briefing_push_enabled' => true,
            'briefing_push_minute' => null,
        ])
        ->assertSessionHasNoErrors();

    $preference = nonNull($user->refresh()->notificationPreference);

    expect($preference->telegram_enabled)->toBeFalse()
        ->and($preference->push_enabled)->toBeTrue()
        ->and($preference->notifications_enabled)->toBeTrue();
});

it('persists the briefing push switch and send time, and clears the time back to automatic', function (): void {
    $user = User::factory()->create();
    $payload = [
        'notifications_enabled' => true,
        'telegram_enabled' => true,
        'push_enabled' => true,
        'briefing_push_enabled' => false,
        'briefing_push_minute' => 5 * 60 + 30,
    ];

    $this->actingAs($user)->patch('/profile/notifications', $payload)->assertSessionHasNoErrors();

    $preference = nonNull($user->refresh()->notificationPreference);
    expect($preference->briefing_push_enabled)->toBeFalse()
        ->and($preference->briefing_push_minute)->toBe(5 * 60 + 30);

    $this->actingAs($user)
        ->patch('/profile/notifications', [...$payload, 'briefing_push_minute' => null])
        ->assertSessionHasNoErrors();

    expect(nonNull($preference->refresh())->briefing_push_minute)->toBeNull();
});
