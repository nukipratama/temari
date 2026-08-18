<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the guest login page', function (): void {
    $this->get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('renders every authenticated page for a fresh user', function (string $route, string $component, array $params = []): void {
    $this->actingAs(User::factory()->create())
        ->get(route($route, $params))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'dashboard' => ['dashboard', 'Home'],
    'history list' => ['history', 'History'],
    'history calendar' => ['history', 'History', ['view' => 'calendar']],
    'cards' => ['cards.index', 'Collection/Cards'],
    'trends' => ['trends', 'Trends'],
    'accessories' => ['accessories', 'Collection/Accessories'],
    'profile' => ['profile', 'Profile'],
    'settings' => ['settings', 'Settings/Index'],
    'race' => ['race', 'Race'],
    'plan' => ['plan', 'Plan'],
]);
