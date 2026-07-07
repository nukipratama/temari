<?php

declare(strict_types=1);

use App\Enums\Rarity;

/*
 * Parity guard: these label strings are mirrored in resources/js/lib/runcard.ts
 * (RARITY_LABELS). The matching Vitest assertion locks the other side, so
 * changing the ladder on one runtime without the other fails a test.
 */
it('exposes the Indonesian rarity ladder labels', function (): void {
    expect(Rarity::Common->label())->toBe('Biasa')
        ->and(Rarity::Uncommon->label())->toBe('Berkesan')
        ->and(Rarity::Rare->label())->toBe('Langka')
        ->and(Rarity::Epic->label())->toBe('Istimewa')
        ->and(Rarity::Legendary->label())->toBe('Legendaris');
});

it('ranks cases from common (0) to legendary (4)', function (): void {
    expect(Rarity::Common->rank())->toBe(0)
        ->and(Rarity::Legendary->rank())->toBe(4)
        ->and(Rarity::Epic->rank())->toBeGreaterThan(Rarity::Rare->rank());
});

/*
* Parity guard: these hex values are mirrored in resources/js/lib/runcard.ts
 * (RARITY_HEX), the client's single source of truth per the docblock. A
 * server-rendered surface (RunCardImageRenderer) that drifts
 * from the client's tint fails here first.
 */
it('exposes the Daybreak rarity hex tints', function (): void {
    expect(Rarity::Common->hexColor())->toBe('#7d8694')
        ->and(Rarity::Uncommon->hexColor())->toBe('#2fb350')
        ->and(Rarity::Rare->hexColor())->toBe('#2f81f7')
        ->and(Rarity::Epic->hexColor())->toBe('#a855f7')
        ->and(Rarity::Legendary->hexColor())->toBe('#f5a623');
});
