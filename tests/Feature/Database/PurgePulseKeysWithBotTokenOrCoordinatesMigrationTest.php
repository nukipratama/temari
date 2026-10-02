<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedPulseKey(string $method, string $uri): string
{
    $key = json_encode([$method, $uri], flags: JSON_THROW_ON_ERROR);

    DB::table('pulse_entries')->insert(['timestamp' => 1_790_000_000, 'type' => 'slow_outgoing_request', 'key' => $key, 'value' => 1500]);
    DB::table('pulse_aggregates')->insert([
        'bucket' => 1_790_000_000, 'period' => 60, 'type' => 'slow_outgoing_request', 'key' => $key,
        'aggregate' => 'max', 'value' => 1500, 'count' => null,
    ]);

    return $key;
}

it('purges stored Pulse keys that carry the bot token or coordinates and keeps the rest', function (): void {
    seedPulseKey('POST', 'https://api.telegram.org/bot123456789:AAFakeBotToken_abc/sendPhoto');
    seedPulseKey('GET', 'https://nominatim.openstreetmap.org/reverse?lat=-6.2146&lon=106.8451&format=jsonv2');
    seedPulseKey('GET', 'https://api.open-meteo.com/v1/forecast?latitude=-6.2146&longitude=106.8451');
    seedPulseKey('GET', 'https://archive-api.open-meteo.com/v1/archive?latitude=-6.2146&longitude=106.8451');
    seedPulseKey('GET', 'https://temari.test/strava/webhook?hub.mode=subscribe&hub.verify_token=fakeVerifyToken&hub.challenge=probe-1');
    $strava = seedPulseKey('GET', 'strava.com/api/v3/*');
    $nominatim = seedPulseKey('GET', 'nominatim.openstreetmap.org/reverse');

    $migration = require base_path('database/migrations/2026_10_02_000500_purge_pulse_keys_with_bot_token_or_coordinates.php');
    $migration->up();
    $migration->up();

    expect(DB::table('pulse_entries')->orderBy('id')->pluck('key')->all())->toBe([$strava, $nominatim])
        ->and(DB::table('pulse_aggregates')->orderBy('id')->pluck('key')->all())->toBe([$strava, $nominatim]);
});
