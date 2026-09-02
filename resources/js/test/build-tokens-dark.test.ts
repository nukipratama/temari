import {
    DARK_INK,
    GROUNDS_DARK,
    inkOnDark,
    RARITY_INK_DARK,
} from '@brand/build-tokens.mjs';
import { contrast, darkGrounds } from '@brand/grounds.mjs';

/**
 * Proof for R1's mitigation (plan/README.md): before any of these values are
 * baked into app.css, this pins that the dark-ground derivation math itself
 * is correct — worst-cased across every dark surface, not eyeballed against
 * one. DesignTokenContrastTest.php separately proves the *shipped CSS*
 * matches what this file proves is derivable; this file guards the
 * generator, that one guards the output.
 */
describe('dark-ground token derivation (build-tokens.mjs)', () => {
    const grounds = Object.values(GROUNDS_DARK);

    it('darkGrounds() returns exactly the Sky family', () => {
        expect(darkGrounds()).toEqual({
            'sky-deep': expect.stringMatching(/^#[0-9a-f]{6}$/),
            sky: expect.stringMatching(/^#[0-9a-f]{6}$/),
            'sky-2': expect.stringMatching(/^#[0-9a-f]{6}$/),
        });
    });

    it('inkOnDark() returns the input unchanged when it already clears the target', () => {
        // horizon (lime) clears 4.5:1 on every dark surface already — proven
        // separately by the contrast sweep below — so lightening it further
        // would needlessly wash out an otherwise-legible accent.
        const horizon = '#ade047';
        expect(inkOnDark(horizon, GROUNDS_DARK)).toBe(horizon);
    });

    it('inkOnDark() lightens toward white when the input does not clear the target', () => {
        // ember is the worst offender on the lightest dark surface (sky-2,
        // ~2.3:1) — inkOnDark must move it, and only ever lighten (raise
        // every channel), never darken.
        const ember = '#b23a4f';
        const lightened = inkOnDark(ember, GROUNDS_DARK);
        expect(lightened).not.toBe(ember);
        const toRgb = (hex: string) => [
            Number.parseInt(hex.slice(1, 3), 16),
            Number.parseInt(hex.slice(3, 5), 16),
            Number.parseInt(hex.slice(5, 7), 16),
        ];
        const [er, eg, eb] = toRgb(ember);
        const [lr, lg, lb] = toRgb(lightened);
        expect(lr).toBeGreaterThanOrEqual(er);
        expect(lg).toBeGreaterThanOrEqual(eg);
        expect(lb).toBeGreaterThanOrEqual(eb);
    });

    it('clears 4.5:1 on every dark ground for the four inverted accent families', () => {
        for (const [family, hex] of Object.entries(DARK_INK)) {
            for (const bg of grounds) {
                expect(
                    contrast(hex, bg),
                    `${family}-ink (dark) ${hex} on ${bg}`,
                ).toBeGreaterThanOrEqual(4.5);
            }
        }
    });

    it('clears 4.5:1 on every dark ground for all five rarity tiers', () => {
        for (const [tier, hex] of Object.entries(RARITY_INK_DARK)) {
            for (const bg of grounds) {
                expect(
                    contrast(hex, bg),
                    `rarity-${tier}-ink (dark) ${hex} on ${bg}`,
                ).toBeGreaterThanOrEqual(4.5);
            }
        }
    });
});
