<?php

declare(strict_types=1);

use App\Models\TelegramConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('disconnects by revoking the connection', function (): void {
    $user = User::factory()->create();
    $connection = TelegramConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete('/profil/telegram')
        ->assertRedirect();

    expect($connection->fresh()->isRevoked())->toBeTrue();
});

it('requires authentication', function (): void {
    $this->delete('/profil/telegram')->assertRedirect('/login');
});
