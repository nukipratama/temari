<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Card;

/**
 * The two export shapes: a 9:16 story and a 1:1 feed post, both 1080 wide.
 */
enum CardAspect: string
{
    case Story = 'story';
    case Feed = 'feed';

    public const int WIDTH = 1080;

    /**
     * The story app's reserved bands. Every style holds its content inside
     * this window; the strip above and below is the card's own ground.
     */
    public const int SAFE_TOP = 270;

    public const int SAFE_BOTTOM = 1650;

    public static function parse(?string $value): self
    {
        return self::tryFrom(mb_strtolower(trim((string) $value))) ?? self::Story;
    }

    public function height(): int
    {
        return $this === self::Story ? 1920 : 1080;
    }

    public function isStory(): bool
    {
        return $this === self::Story;
    }
}
