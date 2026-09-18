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

// Regression for #1009 (reopened): a raw signed decoupling_pct reached
// narrator prompts with no sign convention at all. relationFor() resolves
// which way it points, reusing TIGHT ("barely moved") as the flat band
// rather than inventing a new threshold.
it('resolves relationFor using the existing TIGHT band as the flat threshold', function (): void {
    expect(DecouplingBands::relationFor(14.0))->toBe('up')
        ->and(DecouplingBands::relationFor(-14.0))->toBe('down')
        ->and(DecouplingBands::relationFor(1.0))->toBe('flat')
        ->and(DecouplingBands::relationFor(-1.0))->toBe('flat')
        ->and(DecouplingBands::relationFor(DecouplingBands::TIGHT))->toBe('up');
});
