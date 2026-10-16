<?php

declare(strict_types=1);

namespace App\Services\IntervalsIcu;

use App\Enums\IntervalsIcuVerification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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

    private function request(string $apiKey): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withBasicAuth('API_KEY', $apiKey)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }
}
