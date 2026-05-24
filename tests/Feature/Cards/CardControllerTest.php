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
        'rarity' => 'epic',
        'special_move' => 'Paru-paru Baja',
    ]);

    $this->actingAs($user)->get('/kartu')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Koleksi/Kartu')
            ->has('cards.data', 1)
            ->where('cards.data.0.special_move', 'Paru-paru Baja')
            ->where('cards.data.0.rarity', 'epic'));
});

it('renders the empty state when no cards match the filter', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kartu?rarity=legendary')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Koleksi/Kartu')
            ->where('cards.data', [])
            ->where('selectedRarity', 'legendary'));
});

it('filters by rarity', function (): void {
    $user = User::factory()->create();
    $epicActivity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($epicActivity)->create();
    RunCard::factory()->for($epicActivity)->create([
        'rarity' => 'epic',
        'special_move' => 'EpicMove',
    ]);

    $commonActivity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($commonActivity)->create();
    RunCard::factory()->for($commonActivity)->create([
        'rarity' => 'common',
        'special_move' => 'CommonMove',
    ]);

    $this->actingAs($user)->get('/kartu?rarity=epic')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('cards.data', 1)
            ->where('cards.data.0.special_move', 'EpicMove'));
});

it('exposes a featured card that picks the highest-rarity recent', function (): void {
    $user = User::factory()->create();

    $commonAct = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($commonAct)->create();
    RunCard::factory()->for($commonAct)->create([
        'rarity' => 'common',
        'special_move' => 'Just Common',
    ]);

    $epicAct = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($epicAct)->create();
    RunCard::factory()->for($epicAct)->create([
        'rarity' => 'epic',
        'special_move' => 'Pembalik Keadaan',
    ]);

    $this->actingAs($user)->get('/kartu')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Koleksi/Kartu')
            ->where('featuredCard.special_move', 'Pembalik Keadaan')
            ->where('featuredCard.rarity', 'epic')
            ->where('rarityCounts.epic', 1)
            ->where('rarityCounts.common', 1));
});

it('hides other users\' cards', function (): void {
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    RunCard::factory()->for($activity)->create([
        'special_move' => 'NotForMe',
    ]);

    $me = User::factory()->create();
    $this->actingAs($me)->get('/kartu')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('cards.data', []));
});
