<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs the demo user in when the flag is on and the user exists', function (): void {
    config()->set('demo.login_enabled', true);
    $user = User::factory()->create(['email' => DemoRunSeeder::DEMO_USER_EMAIL]);

    $this->post(route('auth.demo'))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('aborts with 404 when the flag is off', function (): void {
    config()->set('demo.login_enabled', false);
    User::factory()->create(['email' => DemoRunSeeder::DEMO_USER_EMAIL]);

    $this->post(route('auth.demo'))->assertNotFound();
});

it('redirects back to login with an error when the demo user is missing', function (): void {
    config()->set('demo.login_enabled', true);

    $this->post(route('auth.demo'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('demo');

    expect(auth()->check())->toBeFalse();
});

it('renders the demo button on the login page when the flag is on', function (): void {
    config()->set('demo.login_enabled', true);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertSeeText('Coba versi demo');
});

it('hides the demo button when the flag is off', function (): void {
    config()->set('demo.login_enabled', false);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertDontSeeText('Coba versi demo');
});
