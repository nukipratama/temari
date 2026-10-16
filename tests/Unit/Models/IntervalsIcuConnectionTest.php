<?php

declare(strict_types=1);

use App\Models\IntervalsIcuConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
