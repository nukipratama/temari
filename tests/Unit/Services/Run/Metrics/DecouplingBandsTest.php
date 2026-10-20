<?php

declare(strict_types=1);

use App\Services\Run\Metrics\DecouplingBands;

it('reads as a ladder, each band strictly above the last', function (): void {
    expect(DecouplingBands::TIGHT)->toBeLessThan(DecouplingBands::CONTROLLED)
        ->and(DecouplingBands::CONTROLLED)->toBeLessThan(DecouplingBands::HIGH);
});

// Ordinary runs in the corpus land between 6 and 13%, easy and quality days
// alike. A "high" line drawn inside that band fires on a routine Tuesday.
it('draws the high line above the band ordinary runs occupy, and the controlled line below it', function (): void {
    expect(DecouplingBands::HIGH)->toBeGreaterThan(11.0)
        ->and(DecouplingBands::CONTROLLED)->toBeLessThan(6.0);
});

// relationFor() resolves which way a signed decoupling_pct points, using TIGHT
// ("barely moved") as the flat band.
it('resolves relationFor using the existing TIGHT band as the flat threshold', function (): void {
    expect(DecouplingBands::relationFor(14.0))->toBe('up')
        ->and(DecouplingBands::relationFor(-14.0))->toBe('down')
        ->and(DecouplingBands::relationFor(1.0))->toBe('flat')
        ->and(DecouplingBands::relationFor(-1.0))->toBe('flat')
        ->and(DecouplingBands::relationFor(DecouplingBands::TIGHT))->toBe('up');
});
