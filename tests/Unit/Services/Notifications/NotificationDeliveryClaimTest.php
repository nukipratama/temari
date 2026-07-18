<?php

declare(strict_types=1);

use App\Models\AI\Analysis;
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

it('releases a claim so it can be re-claimed', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);

    $claim->claim($id, 'telegram');
    $claim->release($id, 'telegram');

    expect($claim->claim($id, 'telegram'))->toBeTrue();
});

it('releases only the named channel', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($id, 'telegram');
    $claim->claim($id, 'webpush');

    $claim->release($id, 'telegram');

    // The webpush claim survives (re-claim fails); only telegram frees up.
    expect($claim->claim($id, 'webpush'))->toBeFalse()
        ->and($claim->claim($id, 'telegram'))->toBeTrue();
});
