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

it('falls back to the computed mood (not the sleepy default) when no post-run story line exists', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 8_000,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 80, 'Z3' => 20],
            'negative_split' => true,
        ],
        'weather_temp_c' => 25,
    ]);
    RunCard::factory()->for($activity)->create(['rarity' => 'epic']);

    $this->actingAs($user)->get('/kartu')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('cards.data.0.mood', 'enteng'));
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

it('numbers each card by edition within its rarity', function (): void {
    $user = User::factory()->create();
    foreach (['First', 'Second', 'Third'] as $move) {
        $act = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($act)->create();
        RunCard::factory()->for($act)->create(['rarity' => 'rare', 'special_move' => $move]);
    }

    $this->actingAs($user)->get('/kartu')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            // Grid is newest-first; chronological edition numbering runs oldest = #1.
            ->where('cards.data.0.special_move', 'Third')
            ->where('cards.data.0.edition.index', 3)
            ->where('cards.data.0.edition.total', 3)
            ->where('cards.data.2.edition.index', 1)
            ->where('featuredCard.edition', ['index' => 3, 'total' => 3]));
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
