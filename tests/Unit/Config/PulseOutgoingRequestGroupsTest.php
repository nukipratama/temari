<?php

declare(strict_types=1);

use Laravel\Pulse\Recorders\SlowOutgoingRequests;

const FAKE_BOT_TOKEN = '123456789:AAFakeBotToken_abcDEF-0123456789';
const FAKE_LATITUDE = '-6.2146';
const FAKE_LONGITUDE = '106.8451';
const FAKE_STRAVA_SECRET = 'fakeStravaClientSecret0123';
const FAKE_WEBHOOK_VERIFY_TOKEN = 'fakeWebhookVerifyToken0123';
const FAKE_WEBHOOK_CALLBACK_TOKEN = 'fakeWebhookCallbackToken0123';

function pulseOutgoingRequestKey(string $method, string $url): string
{
    $recorder = app(SlowOutgoingRequests::class);
    $group = Closure::bind(fn (string $uri): string => $this->group($uri), $recorder, SlowOutgoingRequests::class);

    return json_encode([$method, $group($url)], flags: JSON_THROW_ON_ERROR);
}

dataset('outbound requests', [
    'telegram sendMessage' => ['POST', 'https://api.telegram.org/bot'.FAKE_BOT_TOKEN.'/sendMessage', 'api.telegram.org/bot*/sendMessage'],
    'telegram sendPhoto' => ['POST', 'https://api.telegram.org/bot'.FAKE_BOT_TOKEN.'/sendPhoto', 'api.telegram.org/bot*/sendPhoto'],
    'telegram setWebhook' => ['POST', 'https://api.telegram.org/bot'.FAKE_BOT_TOKEN.'/setWebhook', 'api.telegram.org/bot*/setWebhook'],
    'nominatim reverse' => [
        'GET',
        'https://nominatim.openstreetmap.org/reverse?lat='.FAKE_LATITUDE.'&lon='.FAKE_LONGITUDE.'&format=jsonv2&zoom=14&addressdetails=1',
        'nominatim.openstreetmap.org/reverse',
    ],
    'open-meteo forecast' => [
        'GET',
        'https://api.open-meteo.com/v1/forecast?latitude='.FAKE_LATITUDE.'&longitude='.FAKE_LONGITUDE.'&hourly=temperature_2m%2Crelative_humidity_2m&timezone=auto&past_days=2&forecast_days=1',
        'api.open-meteo.com/v1/forecast',
    ],
    'open-meteo archive' => [
        'GET',
        'https://archive-api.open-meteo.com/v1/archive?latitude='.FAKE_LATITUDE.'&longitude='.FAKE_LONGITUDE.'&hourly=temperature_2m&timezone=auto&start_date=2026-09-01&end_date=2026-09-01',
        'archive-api.open-meteo.com/v1/archive',
    ],
    'strava api read' => ['GET', 'https://www.strava.com/api/v3/activities/987654321?include_all_efforts=true', 'strava.com/api/v3/*'],
    'strava api-v3 host read' => ['GET', 'https://api-v3.strava.com/athlete/activities?page=1', 'strava.com/api/v3/*'],
    'strava push subscriptions' => [
        'GET',
        'https://www.strava.com/api/v3/push_subscriptions?client_id=12345&client_secret='.FAKE_STRAVA_SECRET,
        'strava.com/api/v3/*',
    ],
    'strava oauth token' => ['POST', 'https://www.strava.com/oauth/token', 'strava.com/oauth/token'],
    'strava oauth deauthorize' => ['POST', 'https://www.strava.com/oauth/deauthorize', 'https://www.strava.com/oauth/deauthorize'],
    'strava webhook probe' => [
        'GET',
        'https://temari.test/strava/webhook/'.FAKE_WEBHOOK_CALLBACK_TOKEN.'?hub.mode=subscribe&hub.verify_token='.FAKE_WEBHOOK_VERIFY_TOKEN.'&hub.challenge=probe-0123456789ab',
        'https://temari.test/strava/webhook/*',
    ],
]);

it('stores each outbound request under a group with no token or coordinates', function (string $method, string $url, string $group): void {
    $key = pulseOutgoingRequestKey($method, $url);

    expect($key)->toBe(json_encode([$method, $group], flags: JSON_THROW_ON_ERROR))
        ->not->toContain(FAKE_BOT_TOKEN)
        ->not->toContain('AAFakeBotToken')
        ->not->toContain(FAKE_LATITUDE)
        ->not->toContain(FAKE_LONGITUDE)
        ->not->toContain('lat=')
        ->not->toContain('latitude=')
        ->not->toContain(FAKE_STRAVA_SECRET)
        ->not->toContain(FAKE_WEBHOOK_VERIFY_TOKEN)
        ->not->toContain(FAKE_WEBHOOK_CALLBACK_TOKEN)
        ->not->toContain('verify_token=');
})->with('outbound requests');
