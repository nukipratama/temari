<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the Settings page for an authed user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Settings'));
});

it('requires auth', function (): void {
    $this->get('/settings')->assertRedirect('/login');
});
