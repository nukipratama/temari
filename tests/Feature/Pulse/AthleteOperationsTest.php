<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Livewire\Pulse\AthleteOperations;
use App\Models\AI\Analysis;
use App\Models\Analytics\StravaSyncLog;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function athleteDelivery(User $user, string $channel, NotificationDeliveryStatus $status): NotificationDelivery
{
    return NotificationDelivery::query()->create([
        'analysis_id' => Analysis::factory()->create([
            'subject_id' => $user->id,
            'discriminator' => (string) Str::uuid(),
        ])->id,
        'channel' => $channel,
        'status' => $status,
        'created_at' => now(),
        'settled_at' => $status === NotificationDeliveryStatus::Pending ? null : now(),
    ]);
}

it('renders without error when there are no athletes', function (): void {
    Livewire::test(AthleteOperations::class)
        ->assertOk()
        ->assertSee('Athletes');
});

it('shows an athlete who has never synced', function (): void {
    User::factory()->create(['name' => 'Rina']);

    Livewire::test(AthleteOperations::class)
        ->assertOk()
        ->assertSee('Rina')
        ->assertSee('never synced')
        ->assertSee('no notification yet');
});

it('attributes a sync that spent list calls to the poll', function (): void {
    $user = User::factory()->create(['name' => 'Rina']);
    StravaSyncLog::log($user->id, 'success', activitiesSynced: 2, apiCallsUsed: 3);

    Livewire::test(AthleteOperations::class)
        ->assertOk()
        ->assertSee('poll');
});

it('attributes a sync that spent no list call to the webhook push', function (): void {
    $user = User::factory()->create(['name' => 'Rina']);
    StravaSyncLog::log($user->id, 'success', activitiesSynced: 1);

    Livewire::test(AthleteOperations::class)
        ->assertOk()
        ->assertSee('webhook');
});

it('alerts when an athlete last sync errored', function (): void {
    $user = User::factory()->create(['name' => 'Rina']);
    StravaSyncLog::log($user->id, 'error', error: 'token rejected');

    Livewire::test(AthleteOperations::class)
        ->assertOk()
        ->assertSee('sync error')
        ->assertSee('health: alert');
});

it('shows the last delivery per channel for the athlete it belongs to', function (): void {
    $user = User::factory()->create(['name' => 'Rina']);
    athleteDelivery($user, 'telegram', NotificationDeliveryStatus::Sent);
    athleteDelivery($user, 'webpush', NotificationDeliveryStatus::Failed);

    Livewire::test(AthleteOperations::class)
        ->assertOk()
        ->assertSee('telegram sent')
        ->assertSee('webpush failed')
        ->assertSee('health: alert');
});

it('lists the demo athlete last and tags them', function (): void {
    User::factory()->create(['name' => 'Demo', 'is_demo' => true]);
    User::factory()->create(['name' => 'Rina']);

    Livewire::test(AthleteOperations::class)
        ->assertOk()
        ->assertSeeInOrder(['Rina', 'Demo', 'demo']);
});
