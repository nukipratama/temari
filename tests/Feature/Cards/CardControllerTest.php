<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        ->assertSeeText('Run Cards')
        ->assertSeeText('Paru-paru Baja')
        ->assertSeeText('Epik');
});

it('renders the empty state when no cards match the filter', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/cards?rarity=legendaris')
        ->assertSuccessful()
        ->assertSeeText('Belum ada kartu di rarity ini');
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
        ->assertSeeText('EpicMove')
        ->assertDontSeeText('CommonMove');
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
        ->assertDontSeeText('NotForMe');
});
