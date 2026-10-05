<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Run\Metrics\FallOffExponent;

/**
 * Which way an athlete's own fitted fall-off leans a race block's session mix.
 * See docs/decisions/supported-race-time-from-recent-efforts.md.
 */
enum FallOffTilt: string
{
    case Endurance = 'endurance';
    case Speed = 'speed';

    public const float ENDURANCE_ABOVE_K = 1.10;

    public const float ENDURANCE_LONG_RUN_FACTOR = 1.10;

    public static function fromFallOff(?float $k, bool $fitted): ?self
    {
        return match (true) {
            $k === null || ! $fitted => null,
            $k <= FallOffExponent::MIN => self::Speed,
            $k > self::ENDURANCE_ABOVE_K => self::Endurance,
            default => null,
        };
    }

    public function qualityType(): SessionType
    {
        return match ($this) {
            self::Endurance => SessionType::Tempo,
            self::Speed => SessionType::Interval,
        };
    }

    public function longRunFactor(): float
    {
        return $this === self::Endurance ? self::ENDURANCE_LONG_RUN_FACTOR : 1.0;
    }
}
