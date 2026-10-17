<?php

declare(strict_types=1);

use App\Enums\IntervalsIcuSyncStatus;
use App\Jobs\IntervalsIcu\SyncIntervalsIcuWorkoutJob;
use App\Models\IntervalsIcuConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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

it("queues today's sync on connect", function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => 'i123456'])]);
    Queue::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/intervals-icu', ['athlete_id' => 'i123456', 'api_key' => 'secret-key']);

    Queue::assertPushed(SyncIntervalsIcuWorkoutJob::class, fn (SyncIntervalsIcuWorkoutJob $job): bool => $job->userId === $user->id);
});

it("keeps today's workout on a reconnect to the same athlete and forgets it for a different one", function (string $athleteId, ?string $eventId): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => $athleteId])]);
    Queue::fake();
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->broken()->create([
        'athlete_id' => 'i123456',
        'synced_on' => now()->toDateString(),
        'sync_status' => IntervalsIcuSyncStatus::Sent,
        'event_id' => '501',
    ]);

    $this->actingAs($user)->post('/settings/intervals-icu', ['athlete_id' => $athleteId, 'api_key' => 'new-key']);

    expect(IntervalsIcuConnection::query()->sole()->event_id)->toBe($eventId);
})->with([
    'same athlete' => ['i123456', '501'],
    'different athlete' => ['i654321', null],
]);

it("removes today's workout from intervals.icu on disconnect", function (): void {
    Http::fake(['intervals.icu/*' => Http::response()]);
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->create(['athlete_id' => 'i42', 'synced_on' => now()->toDateString(), 'event_id' => '501']);

    $this->actingAs($user)->delete('/settings/intervals-icu')->assertRedirect();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://intervals.icu/api/v1/athlete/i42/events/501');
    expect(IntervalsIcuConnection::query()->count())->toBe(0);
});

it('disconnects even when intervals.icu cannot remove the workout', function (int $status): void {
    Http::fake(['intervals.icu/*' => Http::response('', $status)]);
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->create(['synced_on' => now()->toDateString(), 'event_id' => '501']);

    $this->actingAs($user)->delete('/settings/intervals-icu')->assertRedirect();

    expect(IntervalsIcuConnection::query()->count())->toBe(0);
})->with([401, 503]);

it('calls nothing on disconnecting a broken key', function (): void {
    Http::fake();
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->broken()->create(['synced_on' => now()->toDateString(), 'event_id' => '501']);

    $this->actingAs($user)->delete('/settings/intervals-icu')->assertRedirect();

    Http::assertNothingSent();
    expect(IntervalsIcuConnection::query()->count())->toBe(0);
});

it("removes the old athlete's workout before switching to a different athlete", function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => 'i654321'])]);
    Queue::fake();
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->create(['athlete_id' => 'i123456', 'synced_on' => now()->toDateString(), 'event_id' => '501']);

    $this->actingAs($user)->post('/settings/intervals-icu', ['athlete_id' => 'i654321', 'api_key' => 'new-key']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://intervals.icu/api/v1/athlete/i123456/events/501');
    expect(IntervalsIcuConnection::query()->sole()->athlete_id)->toBe('i654321');
});

it("never removes today's workout on a reconnect to the same athlete", function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => 'i123456'])]);
    Queue::fake();
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->create(['athlete_id' => 'i123456', 'synced_on' => now()->toDateString(), 'event_id' => '501']);

    $this->actingAs($user)->post('/settings/intervals-icu', ['athlete_id' => 'i123456', 'api_key' => 'new-key']);

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

it('still switches athlete when the old workout cannot be removed', function (): void {
    Http::fake([
        'intervals.icu/api/v1/athlete/i123456/events/*' => Http::response('', 503),
        'intervals.icu/*' => Http::response(['id' => 'i654321']),
    ]);
    Queue::fake();
    $user = User::factory()->create();
    IntervalsIcuConnection::factory()->for($user)->create(['athlete_id' => 'i123456', 'synced_on' => now()->toDateString(), 'event_id' => '501']);

    $this->actingAs($user)->post('/settings/intervals-icu', ['athlete_id' => 'i654321', 'api_key' => 'new-key'])->assertSessionHasNoErrors();

    $connection = IntervalsIcuConnection::query()->sole();
    expect($connection->athlete_id)->toBe('i654321')
        ->and($connection->event_id)->toBeNull();
});
