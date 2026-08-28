import { RULES } from '@scripts/check-raw-palette.mjs';

// Every fixture below is built via concatenation rather than written as one
// literal, in code AND in comments — check-raw-palette.mjs itself scans
// resources/js line by line with no comment-stripping, so a fixture (or a
// comment naming one) spelled out as a contiguous substring would trip the
// very rule this file tests. grounds.mjs's/DesignTokenContrastTest's own
// source scanners have the same blind spot for the `bg-*` family — an
// unclassified fixture there would separately fail the backend's
// "classifies every background the components paint" gate.
const rawShade = ['bg', 'blue', '500'].join('-');
const legitToken = ['bg', 'sky', '2'].join('-');
const offTokenShadow = ['shadow', 'lg'].join('-');

describe('check-raw-palette rules', () => {
    it('still flags a raw Tailwind palette shade', () => {
        expect(rawShade.match(RULES[0].re)).toEqual([rawShade]);
    });

    it('still flags an off-token shadow utility', () => {
        expect(offTokenShadow.match(RULES[1].re)).toEqual([offTokenShadow]);
    });

    it('does not false-positive a legitimate token that ends in a digit', () => {
        expect(legitToken.match(RULES[0].re)).toBeNull();
    });

    /**
     * F2 removed the off-scale-radius rule after tokening the rest of
     * Tailwind's radius keywords (--radius-2xl/3xl/4xl joined app.css's
     * @theme static). This is the regression test for that removal: exactly
     * two rules remain, and rounded-2xl/3xl/4xl are no longer matched by
     * anything — proving the rule went away because it ran out of a
     * violation to find, not because RULES was silently trimmed further.
     */
    it('has exactly the two rules the docstring documents', () => {
        expect(RULES.map((rule) => rule.name)).toEqual([
            'raw Tailwind palette utility',
            'off-token shadow utility',
        ]);
    });

    it('no longer matches rounded-2xl/3xl/4xl, now that the scale tokens them', () => {
        // `.re` carries the `g` flag, whose `test()` is stateful across calls
        // on the same instance — `.match()` resets internally and is safe to
        // call repeatedly, so it's used here instead.
        for (const className of [
            'rounded-2xl',
            'rounded-3xl',
            'rounded-4xl',
            'rounded-t-2xl',
        ]) {
            expect(
                RULES.some((rule) => className.match(rule.re) !== null),
            ).toBe(false);
        }
    });
});
