<?php

declare(strict_types=1);

use App\Enums\NotificationDeliveryStatus;
use App\Models\AI\Analysis;
use App\Models\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('recovers stale delivery claims for both outbound channels', function (): void {
    $telegram = NotificationDelivery::query()->create([
        'analysis_id' => Analysis::factory()->create(['discriminator' => (string) Str::uuid()])->id,
        'channel' => 'telegram',
        'status' => NotificationDeliveryStatus::Pending,
        'created_at' => now()->subMinutes(16),
        'claimed_at' => now()->subMinutes(16),
        'claim_version' => 1,
    ]);
    $webpush = NotificationDelivery::query()->create([
        'analysis_id' => Analysis::factory()->create(['discriminator' => (string) Str::uuid()])->id,
        'channel' => 'webpush',
        'status' => NotificationDeliveryStatus::Pending,
        'created_at' => now()->subMinutes(16),
        'claimed_at' => now()->subMinutes(16),
        'claim_version' => 1,
    ]);

    $this->artisan('notifications:recover-deliveries')
        ->expectsOutput('Recovered stale deliveries: 1 web push re-armed, 1 Telegram abandoned.')
        ->assertExitCode(0);

    expect($telegram->fresh()->status)->toBe(NotificationDeliveryStatus::Abandoned)
        ->and($telegram->fresh()->claim_version)->toBe(2)
        ->and($webpush->fresh()->status)->toBe(NotificationDeliveryStatus::Pending)
        ->and($webpush->fresh()->claim_version)->toBe(2)
        ->and($webpush->fresh()->claimed_at)->toBeNull();
});
