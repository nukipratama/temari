<?php

declare(strict_types=1);

use App\Enums\IntervalsIcuVerification;
use App\Models\IntervalsIcuConnection;
use App\Services\IntervalsIcu\Exceptions\IntervalsIcuRejectedException;
use App\Services\IntervalsIcu\Exceptions\IntervalsIcuUnreachableException;
use App\Services\IntervalsIcu\IntervalsIcuClient;
use App\Services\IntervalsIcu\IntervalsIcuWorkout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('verifies an athlete with basic auth under the API_KEY username', function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => 'i123456'])]);

    $result = new IntervalsIcuClient()->verify('i123456', 'secret-key');

    expect($result)->toBe(IntervalsIcuVerification::Verified);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://intervals.icu/api/v1/athlete/i123456'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('API_KEY:secret-key')));
});

it('reads an auth or not-found answer as a rejected ID and key', function (int $status): void {
    Http::fake(['intervals.icu/*' => Http::response(['error' => 'Auth failed'], $status)]);

    expect(new IntervalsIcuClient()->verify('i123456', 'wrong'))->toBe(IntervalsIcuVerification::Rejected);
})->with([401, 403, 404]);

it('reads a server error as unreachable rather than a bad key', function (): void {
    Http::fake(['intervals.icu/*' => Http::response('', 503)]);

    expect(new IntervalsIcuClient()->verify('i123456', 'secret-key'))->toBe(IntervalsIcuVerification::Unreachable);
});

it('reads a connection failure as unreachable', function (): void {
    Http::fake(['intervals.icu/*' => fn () => throw new ConnectionException('timed out')]);

    expect(new IntervalsIcuClient()->verify('i123456', 'secret-key'))->toBe(IntervalsIcuVerification::Unreachable);
});

function clientConnection(): IntervalsIcuConnection
{
    return IntervalsIcuConnection::factory()->make(['user_id' => 1, 'athlete_id' => 'i123456', 'api_key' => 'secret-key']);
}

function clientWorkout(): IntervalsIcuWorkout
{
    return new IntervalsIcuWorkout('Easy 8 km', "from temari\n\n- 8km 6:33-7:32/km Pace intensity=active");
}

it('reads the run threshold heart rate from the run sport settings', function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['sportSettings' => [
        ['types' => ['Ride', 'VirtualRide'], 'lthr' => 150],
        ['types' => ['Run', 'TrailRun'], 'lthr' => 163],
    ]])]);

    expect(new IntervalsIcuClient()->runThresholdHeartRate(clientConnection()))->toBe(163);
});

it('reads no run threshold as null', function (array $settings): void {
    Http::fake(['intervals.icu/*' => Http::response(['sportSettings' => $settings])]);

    expect(new IntervalsIcuClient()->runThresholdHeartRate(clientConnection()))->toBeNull();
})->with([
    'no run sport' => [[['types' => ['Ride'], 'lthr' => 150]]],
    'run without threshold' => [[['types' => ['Run'], 'lthr' => null]]],
    'zero threshold' => [[['types' => ['Run'], 'lthr' => 0]]],
]);

it('upserts a whole-day run workout on an external id per athlete and day, and returns its id', function (): void {
    Http::fake(['intervals.icu/*' => Http::response([['id' => 98765]])]);

    $id = new IntervalsIcuClient()->createEvent(clientConnection(), '2026-10-17', clientWorkout());

    expect($id)->toBe('98765');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://intervals.icu/api/v1/athlete/i123456/events/bulk?upsert=true'
        && $request->data() === [[
            'category' => 'WORKOUT',
            'type' => 'Run',
            'start_date_local' => '2026-10-17T00:00:00',
            'name' => 'Easy 8 km',
            'description' => "from temari\n\n- 8km 6:33-7:32/km Pace intensity=active",
            'external_id' => 'temari-1-2026-10-17',
        ]]);
});

/**
 * intervals.icu as measured on 2026-10-20: only the bulk create with
 * `upsert=true` matches an existing event, on its `external_id`; any other
 * create adds a new event and ignores a client `uid`.
 *
 * @param  array<int, array<string, mixed>>  $calendar
 * @return Closure(Request): mixed
 */
function measuredCalendar(array &$calendar, bool &$timesOut): Closure
{
    return function (Request $request) use (&$calendar, &$timesOut) {
        $bulk = str_contains($request->url(), '/events/bulk');
        $upsert = $bulk && str_contains($request->url(), 'upsert=true');
        /** @var array<string, mixed> $event */
        $event = $bulk ? $request->data()[0] : $request->data();
        $match = $upsert ? array_search($event['external_id'] ?? null, array_column($calendar, 'external_id', 'id'), true) : false;
        $id = $match === false ? count($calendar) + 1 : $match;
        $calendar[$id] = [...$event, 'id' => $id];
        if ($timesOut) {
            $timesOut = false;
            throw new ConnectionException('timed out');
        }

        return Http::response($bulk ? [['id' => $id]] : ['id' => $id]);
    };
}

it('adds a second event when a plain create is retried, as measured', function (): void {
    $calendar = [];
    $timesOut = false;
    Http::fake(measuredCalendar($calendar, $timesOut));

    Http::post('https://intervals.icu/api/v1/athlete/i123456/events?upsertOnUid=true', ['uid' => 'temari-1-2026-10-17']);
    Http::post('https://intervals.icu/api/v1/athlete/i123456/events?upsertOnUid=true', ['uid' => 'temari-1-2026-10-17']);

    expect($calendar)->toHaveCount(2);
});

it('keeps one event when a create that landed but timed out is retried', function (): void {
    $calendar = [];
    $timesOut = true;
    Http::fake(measuredCalendar($calendar, $timesOut));
    $client = new IntervalsIcuClient();

    expect(fn () => $client->createEvent(clientConnection(), '2026-10-17', clientWorkout()))->toThrow(IntervalsIcuUnreachableException::class);
    $id = $client->createEvent(clientConnection(), '2026-10-17', clientWorkout());

    expect($calendar)->toHaveCount(1)
        ->and($id)->toBe('1');
});

it('updates an event in place by its id', function (): void {
    Http::fake(['intervals.icu/*' => Http::response(['id' => 98765])]);

    expect(new IntervalsIcuClient()->updateEvent(clientConnection(), '98765', '2026-10-17', clientWorkout()))->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://intervals.icu/api/v1/athlete/i123456/events/98765'
        && $request['name'] === 'Easy 8 km');
});

it('reports an update to a deleted event as gone', function (): void {
    Http::fake(['intervals.icu/*' => Http::response('', 404)]);

    expect(new IntervalsIcuClient()->updateEvent(clientConnection(), '98765', '2026-10-17', clientWorkout()))->toBeFalse();
});

it('deletes an event, and takes an already deleted one as done', function (int $status): void {
    Http::fake(['intervals.icu/*' => Http::response('', $status)]);

    new IntervalsIcuClient()->deleteEvent(clientConnection(), '98765');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://intervals.icu/api/v1/athlete/i123456/events/98765');
})->with([200, 404]);

it('throws rejected on an auth answer from any call', function (string $call, int $status): void {
    Http::fake(['intervals.icu/*' => Http::response('', $status)]);
    $client = new IntervalsIcuClient();

    match ($call) {
        'threshold' => $client->runThresholdHeartRate(clientConnection()),
        'create' => $client->createEvent(clientConnection(), '2026-10-17', clientWorkout()),
        'update' => $client->updateEvent(clientConnection(), '1', '2026-10-17', clientWorkout()),
        'delete' => $client->deleteEvent(clientConnection(), '1'),
        default => throw new InvalidArgumentException("Unknown call {$call}."),
    };
})->with(['threshold', 'create', 'update', 'delete'])->with([401, 403])->throws(IntervalsIcuRejectedException::class);

it('throws rejected when the athlete is gone', function (): void {
    Http::fake(['intervals.icu/*' => Http::response('', 404)]);

    new IntervalsIcuClient()->runThresholdHeartRate(clientConnection());
})->throws(IntervalsIcuRejectedException::class);

it('throws unreachable on a server error', function (): void {
    Http::fake(['intervals.icu/*' => Http::response('', 503)]);

    new IntervalsIcuClient()->createEvent(clientConnection(), '2026-10-17', clientWorkout());
})->throws(IntervalsIcuUnreachableException::class);

it('throws unreachable on a dropped connection', function (): void {
    Http::fake(['intervals.icu/*' => fn () => throw new ConnectionException('timed out')]);

    new IntervalsIcuClient()->createEvent(clientConnection(), '2026-10-17', clientWorkout());
})->throws(IntervalsIcuUnreachableException::class);
