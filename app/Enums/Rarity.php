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
            self::Common => 'Common',
            self::Uncommon => 'Uncommon',
            self::Rare => 'Rare',
            self::Epic => 'Epic',
            self::Legendary => 'Legendary',
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

    /**
     * Threadwork rarity tint, mirrored from the client's `RARITY_HEX`
     * ({@see resources/js/lib/runcard.ts}). Single source of truth for the
     * server-rendered card surface ({@see \App\Services\Run\Story\RunCardImageRenderer}).
     */
    public function hexColor(): string
    {
        return match ($this) {
            self::Common => '#7d8694',
            self::Uncommon => '#2fb350',
            self::Rare => '#2f81f7',
            self::Epic => '#a855f7',
            self::Legendary => '#f5a623',
        };
    }

    /**
     * The `-ink` tier of the same family, mirrored from the light ground's
     * `--color-rarity-*-ink` tokens. An exported card has no ground to follow,
     * so it always takes the light value — the only member of the pair allowed
     * to carry text on paper.
     */
    public function inkColor(): string
    {
        return match ($this) {
            self::Common => '#5f6671',
            self::Uncommon => '#1f7434',
            self::Rare => '#2463be',
            self::Epic => '#8543c4',
            self::Legendary => '#865b13',
        };
    }

    /**
     * The escalating set symbol, mirrored from `RARITY_SYMBOL`
     * ({@see resources/js/lib/runcard.ts}).
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Common => '●',
            self::Uncommon => '◆',
            self::Rare => '★',
            self::Epic => '✦',
            self::Legendary => '✺',
        };
    }

    /**
     * Thread-band accent density (Slice 9c) for the card's rarity chrome —
     * additive texture on top of the existing border/glow, not a re-hue.
     * Mirrored in the client's `RARITY_BAND_COUNT`
     * ({@see resources/js/lib/runcard.ts}).
     */
    public function bandCount(): int
    {
        return match ($this) {
            self::Common => 1,
            self::Uncommon => 2,
            self::Rare => 3,
            self::Epic => 4,
            self::Legendary => 5,
        };
    }
}
