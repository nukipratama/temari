<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('grants admin to a user', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->artisan('user:set-admin', ['userId' => $user->id])
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('revokes admin with --unset', function (): void {
    $user = User::factory()->admin()->create();

    $this->artisan('user:set-admin', ['userId' => $user->id, '--unset' => true])
        ->assertSuccessful();

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('fails when the user does not exist', function (): void {
    $this->artisan('user:set-admin', ['userId' => 99999])
        ->assertFailed();
});
