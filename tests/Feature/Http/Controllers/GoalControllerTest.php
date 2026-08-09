<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the Goals page with goals for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/goals')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Goals')
            ->has('goals')
            ->where('completedCount', 0)
            ->where('totalCount', fn (int $total): bool => $total > 0)
            ->where('goals.0.is_completed', false));
});

it('counts unlocked goals as completed', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_pertama']);

    $this->actingAs($user)->get('/goals')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Goals')
            ->where('completedCount', 1)
            ->where('totalCount', fn (int $total): bool => $total > 0));
});

it('requires authentication', function (): void {
    $this->get('/goals')->assertRedirect('/login');
});
