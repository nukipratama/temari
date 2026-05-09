<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('hashes the password attribute on assignment', function (): void {
    $user = User::factory()->create(['password' => 'secret-plain']);

    expect($user->password)->not->toBe('secret-plain')
        ->and(Hash::check('secret-plain', $user->password))->toBeTrue();
});

it('casts email_verified_at to a Carbon instance', function (): void {
    $user = User::factory()->create(['email_verified_at' => '2026-01-01 00:00:00']);

    expect($user->email_verified_at)->toBeInstanceOf(Carbon::class)
        ->and($user->email_verified_at->toDateString())->toBe('2026-01-01');
});

it('hides sensitive attributes from array serialization', function (): void {
    $user = User::factory()->create();

    $array = $user->toArray();

    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('remember_token');
});
