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
    'dashboard' => ['dashboard', 'HariIni'],
    'aktivitas' => ['aktivitas.index', 'Riwayat/Jejak'],
    'kalender' => ['kalender', 'Riwayat/Kalender'],
    'kartu' => ['kartu.index', 'Koleksi/Kartu'],
    'rekor' => ['rekor', 'Koleksi/Rekor'],
    'aksesori' => ['aksesori', 'Koleksi/Aksesori'],
    'profil' => ['profil', 'Aku'],
]);
