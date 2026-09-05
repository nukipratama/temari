<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Livewire\Pulse\NotificationDeliveryHealth;
use App\Models\AI\Analysis;
use App\Models\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function delivery(string $channel, NotificationDeliveryStatus $status, ?string $error = null): NotificationDelivery
{
    return NotificationDelivery::query()->create([
        'analysis_id' => Analysis::factory()->create(['discriminator' => (string) Str::uuid()])->id,
        'channel' => $channel,
        'status' => $status,
        'error' => $error,
        'created_at' => now(),
        'settled_at' => $status === NotificationDeliveryStatus::Pending ? null : now(),
    ]);
}

it('renders the outcome snapshot without error', function (): void {
    Livewire::test(NotificationDeliveryHealth::class)
        ->assertOk()
        ->assertSee('sent')
        ->assertSee('failed');
});

it('shows an ok health badge when nothing has failed', function (): void {
    delivery('telegram', NotificationDeliveryStatus::Sent);

    Livewire::test(NotificationDeliveryHealth::class)
        ->assertOk()
        ->assertSee('health: ok');
});

it('shows an alert health badge when a send failed', function (): void {
    delivery('telegram', NotificationDeliveryStatus::Failed, 'chat not found');

    Livewire::test(NotificationDeliveryHealth::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('surfaces a failed send with its channel, subject and stored error', function (): void {
    $row = delivery('telegram', NotificationDeliveryStatus::Failed, 'Forbidden: bot was blocked');

    Livewire::test(NotificationDeliveryHealth::class)
        ->assertOk()
        ->assertSee("analysis #{$row->analysis_id}")
        ->assertSee('Forbidden: bot was blocked');
});

it('breaks the outcomes down per channel', function (): void {
    delivery('telegram', NotificationDeliveryStatus::Sent);
    delivery('telegram', NotificationDeliveryStatus::Failed, 'boom');
    delivery('webpush', NotificationDeliveryStatus::Sent);

    Livewire::test(NotificationDeliveryHealth::class)
        ->assertOk()
        ->assertSee('telegram')
        ->assertSee('webpush')
        ->assertSeeInOrder(['telegram', '1 sent', '0 in flight', '1 failed']);
});

it('counts an unsettled claim as in flight rather than delivered', function (): void {
    delivery('telegram', NotificationDeliveryStatus::Pending);

    Livewire::test(NotificationDeliveryHealth::class)
        ->assertOk()
        ->assertSee('health: ok')
        ->assertSeeInOrder(['telegram', '0 sent', '1 in flight', '0 failed']);
});
