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

it('casts the per-type flags to booleans', function (): void {
    $preference = NotificationPreference::factory()->make([
        'post_run' => 1,
        'weekly_recap' => 0,
        'monthly_recap' => 1,
    ]);

    expect($preference->post_run)->toBeTrue()
        ->and($preference->weekly_recap)->toBeFalse()
        ->and($preference->monthly_recap)->toBeTrue();
});

it('is reachable as a hasOne from the user', function (): void {
    $user = User::factory()->create();
    NotificationPreference::factory()->for($user)->create(['post_run' => false]);

    expect($user->notificationPreference)->toBeInstanceOf(NotificationPreference::class)
        ->and($user->notificationPreference->post_run)->toBeFalse();
});
