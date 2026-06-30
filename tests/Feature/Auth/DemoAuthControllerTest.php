<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\Demo\DemoRunSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('logs the demo user in when the flag is on and the user exists', function (): void {
    config()->set('demo.login_enabled', true);
    $user = User::factory()->create(['email' => DemoRunSeeder::DEMO_USER_EMAIL]);

    $this->post(route('auth.demo'))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('returns to a safe `from` deep link after demo login', function (): void {
    config()->set('demo.login_enabled', true);
    User::factory()->create(['email' => DemoRunSeeder::DEMO_USER_EMAIL]);

    $this->post(route('auth.demo'), ['from' => '/aktivitas/13'])
        ->assertRedirect(url('/aktivitas/13'));
});

it('ignores a foreign `from` on demo login and falls back to the dashboard', function (): void {
    config()->set('demo.login_enabled', true);
    User::factory()->create(['email' => DemoRunSeeder::DEMO_USER_EMAIL]);

    $this->post(route('auth.demo'), ['from' => 'https://evil.test/x'])
        ->assertRedirect(route('dashboard'));
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

it('shares the demoLoginEnabled flag on the login page when the flag is on', function (): void {
    config()->set('demo.login_enabled', true);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('demoLoginEnabled', true));
});

it('shares demoLoginEnabled false when the flag is off', function (): void {
    config()->set('demo.login_enabled', false);

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('demoLoginEnabled', false));
});
