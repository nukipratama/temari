<?php

declare(strict_types=1);

use App\Support\Config\AppConfigKey;

it('exposes a code default for every key', function (AppConfigKey $key, mixed $expected): void {
    expect($key->default())->toBe($expected);
})->with([
    'ai.enabled' => [AppConfigKey::AiEnabled, true],
    'strava.enabled' => [AppConfigKey::StravaEnabled, true],
    'breaker threshold' => [AppConfigKey::StravaBreakerThreshold, 5],
    'breaker cooldown' => [AppConfigKey::StravaBreakerCooldownSeconds, 300],
    'breaker state' => [AppConfigKey::StravaBreakerState, 'closed'],
    'breaker failures' => [AppConfigKey::StravaBreakerFailures, 0],
    'breaker opened_at' => [AppConfigKey::StravaBreakerOpenedAt, null],
]);

it('coerces raw values into the canonical type', function (): void {
    expect(AppConfigKey::AiEnabled->cast(0))->toBeFalse()
        ->and(AppConfigKey::AiEnabled->cast(1))->toBeTrue()
        ->and(AppConfigKey::StravaBreakerThreshold->cast('7'))->toBe(7)
        ->and(AppConfigKey::StravaBreakerState->cast('open'))->toBe('open')
        ->and(AppConfigKey::StravaBreakerOpenedAt->cast(null))->toBeNull()
        ->and(AppConfigKey::StravaBreakerOpenedAt->cast('2026-06-09T10:00:00+00:00'))
        ->toBe('2026-06-09T10:00:00+00:00');
});
