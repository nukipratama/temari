<?php

declare(strict_types=1);

use App\Models\IntervalsIcuConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('connects once intervals.icu accepts the ID and key', function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => 'i123456'])]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings')
        ->post('/settings/intervals-icu', ['athlete_id' => '123456', 'api_key' => 'secret-key'])
        ->assertRedirect('/settings')
        ->assertSessionHasNoErrors();

    $connection = $user->intervalsIcuConnection()->firstOrFail();
    expect($connection->athlete_id)->toBe('i123456')
        ->and($connection->api_key)->toBe('secret-key')
        ->and($connection->isBroken())->toBeFalse();
});

it('reconnects a broken connection in place with the new key', function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => 'i123456'])]);
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->broken()->create(['api_key' => 'old-key']);

    $this->actingAs($user)->post('/settings/intervals-icu', ['athlete_id' => 'i123456', 'api_key' => 'new-key']);

    $connection = IntervalsIcuConnection::query()->sole();
    expect($connection->api_key)->toBe('new-key')
        ->and($connection->isBroken())->toBeFalse();
});

it('stores nothing and never flashes the key back when intervals.icu rejects it', function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['error' => 'Auth failed'], 401)]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings')
        ->post('/settings/intervals-icu', ['athlete_id' => 'i123456', 'api_key' => 'secret-key'])
        ->assertRedirect('/settings')
        ->assertSessionHasErrors(['api_key' => "intervals.icu didn't accept that ID and key. check both and try again."])
        ->assertSessionMissing('_old_input.api_key');

    expect(IntervalsIcuConnection::query()->count())->toBe(0);
});

it('says intervals.icu is unreachable instead of blaming the key on a server error', function (): void {
    Http::fake(['intervals.icu/*' => Http::response('', 503)]);

    $this->actingAs(User::factory()->create())
        ->post('/settings/intervals-icu', ['athlete_id' => 'i123456', 'api_key' => 'secret-key'])
        ->assertSessionHasErrors(['api_key' => "couldn't reach intervals.icu just now. try again in a minute."]);

    expect(IntervalsIcuConnection::query()->count())->toBe(0);
});

it('rejects a malformed athlete ID without calling intervals.icu', function (): void {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->post('/settings/intervals-icu', ['athlete_id' => 'not an id', 'api_key' => 'secret-key'])
        ->assertSessionHasErrors('athlete_id');

    Http::assertNothingSent();
});

it('answers a validation error, not a server error, when the fields arrive as arrays', function (): void {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->post('/settings/intervals-icu', ['athlete_id' => ['i123456'], 'api_key' => ['secret-key']])
        ->assertSessionHasErrors(['athlete_id', 'api_key']);

    Http::assertNothingSent();
});

it('disconnects by deleting the stored key', function (): void {
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->from('/settings')
        ->delete('/settings/intervals-icu')
        ->assertRedirect('/settings');

    expect(IntervalsIcuConnection::query()->count())->toBe(0);
});

it('keeps the demo account from connecting or disconnecting', function (): void {
    Http::fake();
    $demo = User::factory()->create(['is_demo' => true]);
    IntervalsIcuConnection::factory()->for($demo)->create();

    $this->actingAs($demo)->post('/settings/intervals-icu', ['athlete_id' => 'i1', 'api_key' => 'key']);
    $this->actingAs($demo)->delete('/settings/intervals-icu');

    Http::assertNothingSent();
    expect(IntervalsIcuConnection::query()->count())->toBe(1);
});
