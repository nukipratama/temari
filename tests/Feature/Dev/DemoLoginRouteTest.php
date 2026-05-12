<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

it('logs in the demo user and redirects to the dashboard with a valid signed URL', function (): void {
    $user = User::factory()->create();

    $url = URL::temporarySignedRoute('demo.login', now()->addDay(), ['user' => $user->id], absolute: false);

    $this->get($url)
        ->assertRedirect(route('dashboard'));

    expect(auth()->id())->toBe($user->id);
});

it('aborts with 403 on an unsigned URL', function (): void {
    $user = User::factory()->create();

    $this->get(route('demo.login', ['user' => $user->id]))
        ->assertForbidden();
});

it('aborts with 404 in production', function (): void {
    app()->detectEnvironment(fn () => 'production');
    $user = User::factory()->create();
    $url = URL::temporarySignedRoute('demo.login', now()->addDay(), ['user' => $user->id], absolute: false);

    $this->get($url)->assertNotFound();
});
