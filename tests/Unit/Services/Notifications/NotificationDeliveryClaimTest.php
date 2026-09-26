<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Models\AI\Analysis;
use App\Models\NotificationDelivery;
use App\Services\Notifications\NotificationDeliveryClaim;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('claims once and rejects a duplicate on the same channel', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);

    expect($claim->claim($id, 'telegram'))->toBe(1)
        ->and($claim->claim($id, 'telegram'))->toBeNull()
        ->and(NotificationDelivery::query()->count())->toBe(1);
});

it('lets the same analysis be claimed independently per channel', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);

    expect($claim->claim($id, 'telegram'))->toBe(1)
        ->and($claim->claim($id, 'webpush'))->toBe(1);
});

it('records a claim as pending until it settles', function (): void {
    $id = Analysis::factory()->create()->id;
    app(NotificationDeliveryClaim::class)->claim($id, 'telegram');

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Pending)
        ->and($row->claimed_at)->not->toBeNull()
        ->and($row->claim_version)->toBe(1);
});

it('settles only the matching claim version', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $version = $claim->claim($id, 'telegram');

    expect($claim->markSent($id, 'telegram', $version))->toBeTrue()
        ->and($claim->markSent($id, 'telegram', $version))->toBeFalse();

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($row->settled_at)->not->toBeNull()
        ->and($row->claimed_at)->toBeNull()
        ->and($row->error)->toBeNull();
});

it('keeps a sent delivery deduped against a later automatic send', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $version = $claim->claim($id, 'telegram');
    $claim->markSent($id, 'telegram', $version);

    expect($claim->claim($id, 'telegram'))->toBeNull();
});

it('records a forced send that never claimed, so a later automatic send is deduped', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);

    expect($claim->recordForcedSent($id, 'telegram'))->toBeTrue()
        ->and($claim->claim($id, 'telegram'))->toBeNull();

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($row->claim_version)->toBe(1);
});

it('fences an in-flight automatic claim when a forced send succeeds', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $oldVersion = $claim->claim($id, 'telegram');

    expect($claim->recordForcedSent($id, 'telegram'))->toBeTrue()
        ->and($claim->markSent($id, 'telegram', $oldVersion))->toBeFalse();

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($row->claim_version)->toBe(2);
});

it('stores the error on a failed send instead of erasing the row', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $version = $claim->claim($id, 'telegram');

    $claim->markFailed($id, 'telegram', $version, 'chat not found');

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($row->error)->toBe('chat not found')
        ->and($row->settled_at)->not->toBeNull()
        ->and($row->claimed_at)->toBeNull();
});

it('lets a retry take over a failed claim with a new version', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $version = $claim->claim($id, 'telegram');
    $claim->markFailed($id, 'telegram', $version, 'boom');

    expect($claim->claim($id, 'telegram'))->toBe(2)
        ->and(NotificationDelivery::query()->count())->toBe(1);

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Pending)
        ->and($row->claim_version)->toBe(2)
        ->and($row->claimed_at)->not->toBeNull()
        ->and($row->error)->toBeNull()
        ->and($row->settled_at)->toBeNull();
});

it('settles only the named channel', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $telegramVersion = $claim->claim($id, 'telegram');
    $claim->claim($id, 'webpush');

    $claim->markFailed($id, 'telegram', $telegramVersion, 'boom');

    expect($claim->claim($id, 'webpush'))->toBeNull()
        ->and($claim->claim($id, 'telegram'))->toBe(2);
});

it('records a failed forced send that never claimed a row', function (): void {
    $id = Analysis::factory()->create()->id;

    expect(app(NotificationDeliveryClaim::class)->recordForcedFailed($id, 'telegram', 'bot blocked'))->toBeTrue();

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($row->error)->toBe('bot blocked');
});

it('does not let a failed forced send overwrite an earlier successful delivery', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->recordForcedSent($id, 'telegram');

    expect($claim->recordForcedFailed($id, 'telegram', 'forced resend blew up'))->toBeFalse()
        ->and(NotificationDelivery::query()->firstOrFail()->status)->toBe(NotificationDeliveryStatus::Sent);
});

it('truncates a runaway error message', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $version = $claim->claim($id, 'telegram');

    $claim->markFailed($id, 'telegram', $version, str_repeat('x', 5000));

    $error = (string) NotificationDelivery::query()->firstOrFail()->error;
    expect(strlen($error))->toBeLessThan(1100)
        ->and($error)->toEndWith('...');
});

it('re-arms stale web pushes and fences the old finisher after reclaim', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $oldVersion = $claim->claim($id, 'webpush');
    NotificationDelivery::query()->where('analysis_id', $id)->update(['claimed_at' => now()->subMinutes(16)]);

    expect($claim->recoverStale())->toBe(['webpush_rearmed' => [$id], 'telegram_abandoned' => 0])
        ->and($claim->markSent($id, 'webpush', $oldVersion))->toBeFalse();

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Pending)
        ->and($row->claim_version)->toBe(2)
        ->and($row->claimed_at)->toBeNull()
        ->and($row->settled_at)->toBeNull();

    $newVersion = $claim->claim($id, 'webpush');
    expect($newVersion)->toBe(3)
        ->and($claim->markFailed($id, 'webpush', $oldVersion, 'late failure'))->toBeFalse()
        ->and($claim->markSent($id, 'webpush', $newVersion))->toBeTrue();
});

it('abandons stale Telegram claims and rejects both re-claim and late settlement', function (): void {
    $id = Analysis::factory()->create()->id;
    $claim = app(NotificationDeliveryClaim::class);
    $oldVersion = $claim->claim($id, 'telegram');
    NotificationDelivery::query()->where('analysis_id', $id)->update(['claimed_at' => now()->subMinutes(16)]);

    expect($claim->recoverStale())->toBe(['webpush_rearmed' => [], 'telegram_abandoned' => 1])
        ->and($claim->claim($id, 'telegram'))->toBeNull()
        ->and($claim->markSent($id, 'telegram', $oldVersion))->toBeFalse();

    $row = NotificationDelivery::query()->firstOrFail();
    expect($row->status)->toBe(NotificationDeliveryStatus::Abandoned)
        ->and($row->claim_version)->toBe(2)
        ->and($row->claimed_at)->toBeNull()
        ->and($row->settled_at)->not->toBeNull()
        ->and($row->error)->toContain('automatic retry was skipped');
});

it('leaves fresh claims untouched during recovery', function (): void {
    $staleId = Analysis::factory()->create(['discriminator' => (string) Str::uuid()])->id;
    $freshId = Analysis::factory()->create(['discriminator' => (string) Str::uuid()])->id;
    $claim = app(NotificationDeliveryClaim::class);
    $claim->claim($staleId, 'telegram');
    $claim->claim($staleId, 'webpush');
    $claim->claim($freshId, 'telegram');
    NotificationDelivery::query()
        ->where('analysis_id', $staleId)
        ->update(['claimed_at' => now()->subMinutes(16)]);

    expect($claim->recoverStale())->toBe(['webpush_rearmed' => [$staleId], 'telegram_abandoned' => 1]);

    $fresh = NotificationDelivery::query()->where('analysis_id', $freshId)->firstOrFail();
    expect($fresh->status)->toBe(NotificationDeliveryStatus::Pending)
        ->and($fresh->claim_version)->toBe(1)
        ->and($fresh->claimed_at)->not->toBeNull()
        ->and(NotificationDelivery::query()->count())->toBe(3);
});
