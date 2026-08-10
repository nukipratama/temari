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

it('renders every authenticated page for a fresh user', function (string $route, string $component): void {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'dashboard' => ['dashboard', 'Today'],
    'activities' => ['activities.index', 'Activities/Feed'],
    'calendar' => ['calendar', 'Activities/Calendar'],
    'cards' => ['cards.index', 'Collection/Cards'],
    'records' => ['records', 'Collection/Records'],
    'accessories' => ['accessories', 'Collection/Accessories'],
    'profile' => ['profile', 'Profile'],
    'settings' => ['settings', 'Settings/Index'],
    'race' => ['race', 'Race'],
]);
