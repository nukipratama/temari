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

    case AiConfigBreakerThreshold = 'ai.config_breaker.threshold';
    case AiConfigBreakerCooldownSeconds = 'ai.config_breaker.cooldown_seconds';

    // Breaker runtime state — managed by AzureConfigCircuitBreaker, not user-tuned.
    case AiConfigBreakerState = 'ai.config_breaker.state';
    case AiConfigBreakerFailures = 'ai.config_breaker.failures';
    case AiConfigBreakerOpenedAt = 'ai.config_breaker.opened_at';

    // Last pause reason pushed to maintainers — managed by MaintainerAlerter so a
    // pause on/off transition is alerted once, not re-sent every self-heal run.
    case AiLastPauseReason = 'ai.last_pause_reason';

    public function default(): mixed
    {
        return match ($this) {
            self::AiEnabled, self::StravaEnabled => true,
            self::StravaBreakerThreshold => 5,
            self::StravaBreakerCooldownSeconds => 300,
            self::StravaBreakerState => 'closed',
            self::StravaBreakerFailures => 0,
            self::StravaBreakerOpenedAt => null,
            // A misconfigured key/base URL keeps failing, so trip fast (3) and
            // probe every 15 minutes so a fixed env auto-resumes for free.
            self::AiConfigBreakerThreshold => 3,
            self::AiConfigBreakerCooldownSeconds => 900,
            self::AiConfigBreakerState => 'closed',
            self::AiConfigBreakerFailures => 0,
            self::AiConfigBreakerOpenedAt => null,
            self::AiLastPauseReason => null,
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
            self::StravaBreakerFailures,
            self::AiConfigBreakerThreshold,
            self::AiConfigBreakerCooldownSeconds,
            self::AiConfigBreakerFailures => (int) $value,
            self::StravaBreakerState => (string) $value,
            self::AiConfigBreakerState => (string) $value,
            self::StravaBreakerOpenedAt => $value === null ? null : (string) $value,
            self::AiConfigBreakerOpenedAt => $value === null ? null : (string) $value,
            self::AiLastPauseReason => $value === null ? null : (string) $value,
        };
    }
}
