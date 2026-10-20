<?php

declare(strict_types=1);

namespace App\Services\IntervalsIcu;

use App\Enums\IntervalsIcuVerification;
use App\Models\IntervalsIcuConnection;
use App\Services\IntervalsIcu\Exceptions\IntervalsIcuRejectedException;
use App\Services\IntervalsIcu\Exceptions\IntervalsIcuUnreachableException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * intervals.icu's personal-key API: Basic auth with the literal username
 * `API_KEY` and the athlete's key as the password.
 */
class IntervalsIcuClient
{
    private const string BASE_URL = 'https://intervals.icu/api/v1';

    private const int TIMEOUT_SECONDS = 10;

    public function verify(string $athleteId, string $apiKey): IntervalsIcuVerification
    {
        try {
            $response = $this->request($apiKey)->get('/athlete/'.rawurlencode($athleteId));
        } catch (ConnectionException) {
            return IntervalsIcuVerification::Unreachable;
        }

        if ($response->successful()) {
            return IntervalsIcuVerification::Verified;
        }

        return in_array($response->status(), [401, 403, 404], true)
            ? IntervalsIcuVerification::Rejected
            : IntervalsIcuVerification::Unreachable;
    }

    /**
     * The threshold heart rate the athlete set for running on intervals.icu,
     * which `% LTHR` targets are read against there.
     */
    public function runThresholdHeartRate(IntervalsIcuConnection $connection): ?int
    {
        $response = $this->call($connection, fn (PendingRequest $request): Response => $request->get(self::athletePath($connection)));
        if ($response->status() === 404) {
            throw new IntervalsIcuRejectedException('intervals.icu has no athlete '.$connection->athlete_id);
        }
        self::ensureSuccessful($response);

        /** @var list<array{types?: list<string>, lthr?: int|null}> $settings */
        $settings = $response->json('sportSettings') ?? [];
        foreach ($settings as $sport) {
            if (in_array('Run', $sport['types'] ?? [], true)) {
                return isset($sport['lthr']) && $sport['lthr'] > 0 ? (int) $sport['lthr'] : null;
            }
        }

        return null;
    }

    /**
     * Upserts on a `uid` fixed per athlete and day, so a retry after a create
     * that landed but timed out updates that event instead of adding another.
     *
     * @return string the event's id
     */
    public function createEvent(IntervalsIcuConnection $connection, string $date, IntervalsIcuWorkout $workout): string
    {
        $event = [...self::event($date, $workout), 'uid' => 'temari-'.$connection->user_id.'-'.$date];
        $response = $this->call($connection, fn (PendingRequest $request): Response => $request->post(self::athletePath($connection).'/events?upsertOnUid=true', $event));
        self::ensureSuccessful($response);
        $id = $response->json('id');
        assert(is_scalar($id));

        return (string) $id;
    }

    /** @return bool false when the event is gone from the calendar */
    public function updateEvent(IntervalsIcuConnection $connection, string $eventId, string $date, IntervalsIcuWorkout $workout): bool
    {
        $response = $this->call($connection, fn (PendingRequest $request): Response => $request->put(self::athletePath($connection).'/events/'.rawurlencode($eventId), self::event($date, $workout)));
        if ($response->status() === 404) {
            return false;
        }
        self::ensureSuccessful($response);

        return true;
    }

    public function deleteEvent(IntervalsIcuConnection $connection, string $eventId): void
    {
        $response = $this->call($connection, fn (PendingRequest $request): Response => $request->delete(self::athletePath($connection).'/events/'.rawurlencode($eventId)));
        if ($response->status() !== 404) {
            self::ensureSuccessful($response);
        }
    }

    /** @return array<string, string> */
    private static function event(string $date, IntervalsIcuWorkout $workout): array
    {
        return [
            'category' => 'WORKOUT',
            'type' => 'Run',
            'start_date_local' => $date.'T00:00:00',
            'name' => $workout->name,
            'description' => $workout->description,
        ];
    }

    private static function athletePath(IntervalsIcuConnection $connection): string
    {
        return '/athlete/'.rawurlencode($connection->athlete_id);
    }

    /** @param  Closure(PendingRequest): Response  $send */
    private function call(IntervalsIcuConnection $connection, Closure $send): Response
    {
        try {
            $response = $send($this->request($connection->api_key));
        } catch (ConnectionException $e) {
            throw new IntervalsIcuUnreachableException($e->getMessage(), previous: $e);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new IntervalsIcuRejectedException('intervals.icu answered '.$response->status());
        }

        return $response;
    }

    private static function ensureSuccessful(Response $response): void
    {
        if (! $response->successful()) {
            throw new IntervalsIcuUnreachableException('intervals.icu answered '.$response->status());
        }
    }

    private function request(string $apiKey): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth('API_KEY', $apiKey)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }
}
