<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backed enum for run-card rarity. Cases are ordered from least to most rare,
 * so {@see self::rank()} can compare progression by `cases()` index.
 */
enum Rarity: string
{
    case Common = 'common';
    case Uncommon = 'uncommon';
    case Rare = 'rare';
    case Epic = 'epic';
    case Legendary = 'legendary';

    public function label(): string
    {
        return match ($this) {
            self::Common => 'Biasa',
            self::Uncommon => 'Berkesan',
            self::Rare => 'Langka',
            self::Epic => 'Luar Biasa',
            self::Legendary => 'Legendaris',
        };
    }

    /**
     * Position in the ordered cases list (0 = Common, 4 = Legendary). Used to
     * compare whether a rebuilt card climbed in rarity since the last build.
     */
    public function rank(): int
    {
        // array_search is guaranteed to find $this in self::cases() since the
        // value comes from the enum itself, so the false branch is unreachable.
        return (int) array_search($this, self::cases(), strict: true);
    }
}
