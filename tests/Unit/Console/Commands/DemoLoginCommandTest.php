<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => app()->detectEnvironment(fn () => 'testing'));

it('prints a signed login URL for the demo user', function (): void {
    User::factory()->create(['email' => DemoRunSeeder::DEMO_USER_EMAIL]);

    $this->artisan('demo:login')
        ->expectsOutputToContain('One-tap demo login')
        ->expectsOutputToContain('/dev/login-as-demo/')
        ->assertSuccessful();
});

it('errors when not in local or testing env', function (): void {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:login')
        ->expectsOutputToContain('only available in the local environment')
        ->assertFailed();
});

it('errors when the demo user has not been seeded', function (): void {
    $this->artisan('demo:login')
        ->expectsOutputToContain('Demo user not found')
        ->assertFailed();
});
