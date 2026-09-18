<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Card;

/**
 * The optional facts the athlete can toggle on the share popup. Everything
 * here is additive chrome: distance, time, pace, route, date, place and the
 * wordmark are on every card and have no switch.
 */
final readonly class CardOptions
{
    public function __construct(
        public bool $heartRate = true,
        public bool $elevation = true,
        public bool $weather = true,
        public bool $badges = true,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            heartRate: self::flag($input, 'hr'),
            elevation: self::flag($input, 'elevation'),
            weather: self::flag($input, 'weather'),
            badges: self::flag($input, 'badges'),
        );
    }

    /** A stable, short discriminator for the render cache key. */
    public function cacheKey(): string
    {
        return ($this->heartRate ? '1' : '0')
            .($this->elevation ? '1' : '0')
            .($this->weather ? '1' : '0')
            .($this->badges ? '1' : '0');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function flag(array $input, string $key): bool
    {
        return filter_var($input[$key] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }
}
