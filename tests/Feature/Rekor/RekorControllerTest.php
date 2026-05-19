<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the PR ledger', function (): void {
    $user = User::factory()->create();

    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500.0,
    ]);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Rekor')
            ->has('personalRecords', 1)
            ->where('personalRecords.0.category', '5km'));
});

it('shows empty PR ledger when the user has none', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Rekor')
            ->where('personalRecords', []));
});
