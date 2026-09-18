<?php

declare(strict_types=1);

namespace App\Services\Run\Story\Card;

/**
 * One print style. Returns a complete, self-contained SVG document at the
 * aspect's exact pixel size, ready for librsvg.
 */
interface CardStyleRenderer
{
    public function render(CardFacts $facts, CardAspect $aspect): string;
}
