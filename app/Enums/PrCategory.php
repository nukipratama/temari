<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for personal-record categories. Distance cases store the time
 * to cover that distance; effort cases store the fastest pace held over the
 * named time window. Use {@see self::isDistance()} to route formatting.
 */
enum PrCategory: string
{
    case Km1 = '1km';
    case Km5 = '5km';
    case Km10 = '10km';
    case Km15 = '15km';
    case HalfMarathon = 'half_marathon';
    case Marathon = 'marathon';
    case Best5Min = 'best_5min';
    case Best10Min = 'best_10min';
    case Best20Min = 'best_20min';
    case Best30Min = 'best_30min';
    case Best60Min = 'best_60min';

    public function label(): string
    {
        return match ($this) {
            self::Km1 => '1 km',
            self::Km5 => '5 km',
            self::Km10 => '10 km',
            self::Km15 => '15 km',
            self::HalfMarathon => 'Half Marathon',
            self::Marathon => 'Marathon',
            self::Best5Min => 'Best 5 minutes',
            self::Best10Min => 'Best 10 minutes',
            self::Best20Min => 'Best 20 minutes',
            self::Best30Min => 'Best 30 minutes',
            self::Best60Min => 'Best 60 minutes',
        };
    }

    public function isDistance(): bool
    {
        return $this->distanceMeters() !== null;
    }

    public function distanceMeters(): ?float
    {
        return match ($this) {
            self::Km1 => 1_000.0,
            self::Km5 => 5_000.0,
            self::Km10 => 10_000.0,
            self::Km15 => 15_000.0,
            self::HalfMarathon => 21_097.5,
            self::Marathon => 42_195.0,
            default => null,
        };
    }

    /**
     * Stream-summary key the effort-pace cases read from. Distance cases
     * return null since they derive their PR value from elapsed time, not
     * a precomputed best-window pace.
     */
    public function effortStreamKey(): ?string
    {
        return match ($this) {
            self::Best5Min => 'best_5min_pace',
            self::Best10Min => 'best_10min_pace',
            self::Best20Min => 'best_20min_pace',
            self::Best30Min => 'best_30min_pace',
            self::Best60Min => 'best_60min_pace',
            default => null,
        };
    }

    /** @return list<self> */
    public static function distances(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isDistance()));
    }

    /** @return list<self> */
    public static function efforts(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => ! $c->isDistance()));
    }
}
