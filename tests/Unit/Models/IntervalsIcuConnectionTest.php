<?php

declare(strict_types=1);

use App\Enums\IntervalsIcuSyncStatus;
use App\Models\IntervalsIcuConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores the API key encrypted and keeps it out of serialisation', function (): void {
    $connection = IntervalsIcuConnection::factory()->create(['api_key' => 'plain-secret']);

    $raw = DB::table('intervals_icu_connections')->where('id', $connection->id)->value('api_key');

    expect($raw)->not->toContain('plain-secret')
        ->and($connection->fresh()?->api_key)->toBe('plain-secret')
        ->and($connection->toArray())->not->toHaveKey('api_key');
});

it('is broken once intervals.icu stops accepting the key', function (): void {
    expect(IntervalsIcuConnection::factory()->make()->isBroken())->toBeFalse()
        ->and(IntervalsIcuConnection::factory()->broken()->make()->isBroken())->toBeTrue();
});

it('belongs to its user and goes with the account', function (): void {
    $user = User::factory()->create();
    $connection = IntervalsIcuConnection::factory()->for($user)->create();

    expect($connection->user->is($user))->toBeTrue()
        ->and($user->intervalsIcuConnection?->is($connection))->toBeTrue();

    $user->delete();

    expect(IntervalsIcuConnection::query()->count())->toBe(0);
});

it('reports the sync status only for the day the sync last looked at', function (): void {
    $connection = IntervalsIcuConnection::factory()->make(['synced_on' => '2026-10-14', 'sync_status' => IntervalsIcuSyncStatus::Sent]);

    expect($connection->statusOn(Carbon::parse('2026-10-14 18:00')))->toBe(IntervalsIcuSyncStatus::Sent)
        ->and($connection->statusOn(Carbon::parse('2026-10-15')))->toBeNull()
        ->and(IntervalsIcuConnection::factory()->make()->statusOn(Carbon::parse('2026-10-14')))->toBeNull();
});
