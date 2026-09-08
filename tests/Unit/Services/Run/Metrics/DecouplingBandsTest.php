<?php

declare(strict_types=1);

use App\Services\Run\Metrics\DecouplingBands;

it('reads as a ladder, each band strictly above the last', function (): void {
    expect(DecouplingBands::TIGHT)->toBeLessThan(DecouplingBands::CONTROLLED)
        ->and(DecouplingBands::CONTROLLED)->toBeLessThan(DecouplingBands::HIGH)
        ->and(DecouplingBands::HIGH)->toBeLessThan(DecouplingBands::EGREGIOUS);
});

// Ordinary runs in the corpus land between 6 and 13%, easy and quality days
// alike. A "high" line drawn inside that band fires on a routine Tuesday, which
// is how one Sunday came to speak for a whole week.
it('draws the high line above the band ordinary runs occupy, and the controlled line below it', function (): void {
    expect(DecouplingBands::HIGH)->toBeGreaterThan(11.0)
        ->and(DecouplingBands::CONTROLLED)->toBeLessThan(6.0);
});
