<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $connection = TelegramConnection::factory()->create();

    expect($connection->user)->toBeInstanceOf(User::class);
});

it('casts chat id and notify flags', function (): void {
    $connection = TelegramConnection::factory()->create([
        'chat_id' => 12345,
        'notify_post_run' => true,
        'notify_weekly_recap' => false,
        'notify_monthly_recap' => true,
        'notify_daily_briefing' => true,
    ]);

    expect($connection->chat_id)->toBeInt()
        ->and($connection->notify_post_run)->toBeTrue()
        ->and($connection->notify_weekly_recap)->toBeFalse()
        ->and($connection->notify_monthly_recap)->toBeTrue()
        ->and($connection->notify_daily_briefing)->toBeTrue();
});

it('reports revoked state and marks revoked once', function (): void {
    $connection = TelegramConnection::factory()->create();

    expect($connection->isRevoked())->toBeFalse();

    $connection->markRevoked();
    $revokedAt = $connection->revoked_at;

    expect($connection->isRevoked())->toBeTrue()
        ->and($revokedAt)->not->toBeNull();

    // Idempotent: a second call does not move the timestamp.
    $connection->markRevoked();
    expect($connection->fresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
});

it('active scope excludes revoked connections', function (): void {
    $active = TelegramConnection::factory()->create();
    TelegramConnection::factory()->revoked()->create();

    $ids = TelegramConnection::query()->active()->pluck('id');

    expect($ids)->toContain($active->id)
        ->and($ids)->toHaveCount(1);
});
