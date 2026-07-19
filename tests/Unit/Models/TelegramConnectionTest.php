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

it('casts chat id to an integer', function (): void {
    $connection = TelegramConnection::factory()->make([
        'user_id' => 1,
        'chat_id' => 12345,
    ]);

    expect($connection->chat_id)->toBeInt();
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
