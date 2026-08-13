<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('logs the demo user in when the flag is on and the user exists', function (): void {
    config()->set('demo.login_enabled', true);
    $user = User::factory()->demo()->create();

    $this->post(route('auth.demo'))
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('returns to a safe `from` deep link after demo login', function (): void {
    config()->set('demo.login_enabled', true);
    User::factory()->demo()->create();

    $this->post(route('auth.demo'), ['from' => '/activities/13'])
        ->assertRedirect(url('/activities/13'));
});

it('ignores a foreign `from` on demo login and falls back to the dashboard', function (): void {
    config()->set('demo.login_enabled', true);
    User::factory()->demo()->create();

    $this->post(route('auth.demo'), ['from' => 'https://evil.test/x'])
        ->assertRedirect(route('dashboard'));
});

it('aborts with 404 when the flag is off', function (): void {
    config()->set('demo.login_enabled', false);
    User::factory()->demo()->create();

    $this->post(route('auth.demo'))->assertNotFound();
});

// The demo user used to be found by the seeder's email constant. Now that any
// stranger can sign up carrying whatever address Strava hands over, the lookup
// keys off the same is_demo flag every scheduler and guard already trusts.
it('never hands the demo session to a signed-up user wearing the demo email', function (): void {
    config()->set('demo.login_enabled', true);
    $demo = User::factory()->demo()->create(['email' => 'demo@temari.local']);
    $demo->forceFill(['email' => null])->save();
    $impostor = User::factory()->create(['email' => 'demo@temari.local']);

    $this->post(route('auth.demo'))->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($demo->id)
        ->and(auth()->id())->not->toBe($impostor->id);
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
