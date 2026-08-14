<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Models\AI\Analysis;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('claims once and rejects a duplicate on the same channel', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);

    expect($claim->claim($id, 'telegram'))->toBeTrue()
        ->and($claim->claim($id, 'telegram'))->toBeFalse();
});

it('lets the same analysis be claimed independently per channel', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);

    expect($claim->claim($id, 'telegram'))->toBeTrue()
        ->and($claim->claim($id, 'webpush'))->toBeTrue();
});

it('records a claim as pending until it settles', function (): void {
    $id = Analysis::factory()->create()->id;
    app(NotificationDeliveryClaim::class)->claim($id, 'telegram');

    expect(NotificationDelivery::query()->firstOrFail()->status)
        ->toBe(NotificationDeliveryStatus::Pending);
});

it('settles a delivered claim as sent with a settled_at stamp', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($id, 'telegram');

    $claim->markSent($id, 'telegram');

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($row->settled_at)->not->toBeNull()
        ->and($row->error)->toBeNull();
});

it('keeps a sent delivery deduped against a later automatic send', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($id, 'telegram');
    $claim->markSent($id, 'telegram');

    expect($claim->claim($id, 'telegram'))->toBeFalse();
});

it('records a forced send that never claimed, so a later automatic send is deduped', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);

    $claim->markSent($id, 'telegram');

    expect(NotificationDelivery::query()->firstOrFail()->status)
        ->toBe(NotificationDeliveryStatus::Sent)
        ->and($claim->claim($id, 'telegram'))->toBeFalse();
});

it('stores the error on a failed send instead of erasing the row', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($id, 'telegram');

    $claim->markFailed($id, 'telegram', 'chat not found');

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($row->error)->toBe('chat not found')
        ->and($row->settled_at)->not->toBeNull();
});

it('lets a retry take over a failed claim', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($id, 'telegram');
    $claim->markFailed($id, 'telegram', 'boom');

    expect($claim->claim($id, 'telegram'))->toBeTrue()
        ->and(NotificationDelivery::query()->count())->toBe(1);

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Pending)
        ->and($row->error)->toBeNull()
        ->and($row->settled_at)->toBeNull();
});

it('settles only the named channel', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($id, 'telegram');
    $claim->claim($id, 'webpush');

    $claim->markFailed($id, 'telegram', 'boom');

    // The webpush claim survives (re-claim fails); only telegram frees up.
    expect($claim->claim($id, 'webpush'))->toBeFalse()
        ->and($claim->claim($id, 'telegram'))->toBeTrue();
});

it('does not let a failed forced send overwrite an earlier successful delivery', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($id, 'telegram');
    $claim->markSent($id, 'telegram');

    $claim->markFailed($id, 'telegram', 'forced resend blew up');

    expect(NotificationDelivery::query()->firstOrFail()->status)
        ->toBe(NotificationDeliveryStatus::Sent);
});

it('records a failed forced send that never claimed a row', function (): void {
    $id = Analysis::factory()->create()->id;

    app(NotificationDeliveryClaim::class)->markFailed($id, 'telegram', 'bot blocked');

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($row->error)->toBe('bot blocked');
});

it('truncates a runaway error message', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($id, 'telegram');

    $claim->markFailed($id, 'telegram', str_repeat('x', 5000));

    $error = (string) NotificationDelivery::query()->firstOrFail()->error;
    expect(strlen($error))->toBeLessThan(1100)
        ->and($error)->toEndWith('...');
});
