<?php

declare(strict_types=1);

use App\Livewire\Pulse\StravaHealth;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders connection states and rate-limit headroom without error', function (): void {
    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('Koneksi')
        ->assertSee('Sisa kuota API')
        ->assertSee('15 menit');
});

it('counts a revoked connection in the revoked bucket', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'revoked_at' => Carbon::now(),
    ]);

    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('dicabut');
});
