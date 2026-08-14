<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Models\AI\Analysis;
use App\Models\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('casts the outcome columns', function (): void {
    $analysisId = Analysis::factory()->create()->id;

    NotificationDelivery::query()->create([
        'analysis_id' => (string) $analysisId,
        'channel' => 'telegram',
        'status' => NotificationDeliveryStatus::Sent,
        'created_at' => now(),
        'settled_at' => '2026-08-14 09:00:00',
    ]);

    $row = NotificationDelivery::query()->firstOrFail();

    expect($row->analysis_id)->toBeInt()
        ->and($row->analysis_id)->toBe($analysisId)
        ->and($row->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($row->settled_at)->toBeInstanceOf(Carbon::class);
});

it('cascades away with its analysis', function (): void {
    $analysis = Analysis::factory()->create();
    NotificationDelivery::query()->create([
        'analysis_id' => $analysis->id,
        'channel' => 'telegram',
        'status' => NotificationDeliveryStatus::Sent,
        'created_at' => now(),
    ]);

    $analysis->delete();

    expect(NotificationDelivery::query()->count())->toBe(0);
});
