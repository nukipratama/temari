<?php

declare(strict_types=1);

use App\Models\Analytics\StravaSyncLog;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes the account, revokes Strava, logs the user out and redirects to login', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $this->actingAs($user)->delete('/akun')
        ->assertRedirect(route('login'))
        ->assertSessionHas('info');

    // The connection row cascade-deletes with the user; the `deleting` hook's
    // revoke + sync-log write is the observable proof it ran (see UserTest).
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(StravaSyncLog::query()->where('user_id', $user->id)->where('status', 'deleted')->exists())->toBeTrue();

    $this->assertGuest();
});

it('rejects a guest', function (): void {
    $this->delete('/akun')->assertRedirect('/login');
});

it('refuses to delete the demo account', function (): void {
    $demo = User::factory()->create(['is_demo' => true]);

    $this->actingAs($demo)->delete('/akun')
        ->assertSessionHasErrors('akun');

    expect(User::query()->whereKey($demo->id)->exists())->toBeTrue();
    $this->assertAuthenticatedAs($demo);
});
