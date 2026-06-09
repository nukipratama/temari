<?php

declare(strict_types=1);

namespace App\Support\Config;

/**
 * The runtime control-plane keys. Each key's default lives here in code; a row in
 * the `app_config` table only exists once the value is overridden at runtime.
 */
enum AppConfigKey: string
{
    case AiEnabled = 'ai.enabled';
    case StravaEnabled = 'strava.enabled';

    case StravaBreakerThreshold = 'strava.breaker.threshold';
    case StravaBreakerCooldownSeconds = 'strava.breaker.cooldown_seconds';

    // Breaker runtime state — managed by StravaCircuitBreaker, not user-tuned.
    case StravaBreakerState = 'strava.breaker.state';
    case StravaBreakerFailures = 'strava.breaker.failures';
    case StravaBreakerOpenedAt = 'strava.breaker.opened_at';

    public function default(): mixed
    {
        return match ($this) {
            self::AiEnabled, self::StravaEnabled => true,
            self::StravaBreakerThreshold => 5,
            self::StravaBreakerCooldownSeconds => 300,
            self::StravaBreakerState => 'closed',
            self::StravaBreakerFailures => 0,
            self::StravaBreakerOpenedAt => null,
        };
    }

    /**
     * Coerce a raw stored/decoded value into this key's canonical PHP type.
     */
    public function cast(mixed $value): mixed
    {
        return match ($this) {
            self::AiEnabled, self::StravaEnabled => (bool) $value,
            self::StravaBreakerThreshold,
            self::StravaBreakerCooldownSeconds,
            self::StravaBreakerFailures => (int) $value,
            self::StravaBreakerState => (string) $value,
            self::StravaBreakerOpenedAt => $value === null ? null : (string) $value,
        };
    }
}
