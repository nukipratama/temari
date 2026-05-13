<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shows the user\'s cards on the gallery', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['name' => 'Tempo Run']);
    RunCard::factory()->for($activity)->create([
        'rarity' => 'epik',
        'special_move' => 'Paru-paru Baja',
    ]);

    $this->actingAs($user)->get('/cards')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cards/Index')
            ->has('cards.data', 1)
            ->where('cards.data.0.special_move', 'Paru-paru Baja')
            ->where('cards.data.0.rarity', 'epik'));
});

it('renders the empty state when no cards match the filter', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/cards?rarity=legendaris')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Cards/Index')
            ->where('cards.data', [])
            ->where('selectedRarity', 'legendaris'));
});

it('filters by rarity', function (): void {
    $user = User::factory()->create();
    $epicActivity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($epicActivity)->create();
    RunCard::factory()->for($epicActivity)->create([
        'rarity' => 'epik',
        'special_move' => 'EpicMove',
    ]);

    $commonActivity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($commonActivity)->create();
    RunCard::factory()->for($commonActivity)->create([
        'rarity' => 'biasa',
        'special_move' => 'CommonMove',
    ]);

    $this->actingAs($user)->get('/cards?rarity=epik')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('cards.data', 1)
            ->where('cards.data.0.special_move', 'EpicMove'));
});

it('hides other users\' cards', function (): void {
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    RunCard::factory()->for($activity)->create([
        'special_move' => 'NotForMe',
    ]);

    $me = User::factory()->create();
    $this->actingAs($me)->get('/cards')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('cards.data', []));
});
