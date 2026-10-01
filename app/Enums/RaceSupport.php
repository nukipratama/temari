<?php

declare(strict_types=1);

namespace App\Enums;

enum RaceSupport: string
{
    case Road = 'road';
    case GeneralMaintenance = 'general_maintenance';

    public const float MARATHON_CLASS_ABOVE_M = 25_000.0;

    public const float ROAD_LIMIT_M = 42_300.0;

    public static function forDistance(float $distanceM): self
    {
        return $distanceM <= self::ROAD_LIMIT_M ? self::Road : self::GeneralMaintenance;
    }

    public static function isMarathonClass(?float $distanceM): bool
    {
        return $distanceM !== null
            && $distanceM > self::MARATHON_CLASS_ABOVE_M
            && self::forDistance($distanceM) === self::Road;
    }

    public function dedicatedPreparation(): bool
    {
        return $this === self::Road;
    }

    public function limitation(): ?string
    {
        return $this === self::GeneralMaintenance
            ? 'this is general aerobic maintenance toward your race. dedicated ultra preparation is not supported yet.'
            : null;
    }
}
