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
const pxFontSize = ['text', '[13px]'].join('-');
const remFontSize = ['text', '[0.8125rem]'].join('-');
const inlinePxFont = ['fontSize', ' 20'].join(':');
const canvasFont = ['700 30px "JetBrains', 'Mono"'].join(' ');

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
     * T2 stepped the root font-size 20% at >=1280px and left open that nothing
     * stopped a new px literal from silently opting out of it. These two rules
     * close that, and W4 added them while the tree held zero violations — so
     * without a deliberate fixture they would pass forever whether or not they
     * matched anything.
     */
    it('flags a px font-size utility', () => {
        expect(pxFontSize.match(RULES[2].re)).toEqual([pxFontSize]);
    });

    it('flags an inline px font-size style prop', () => {
        expect(inlinePxFont.match(RULES[3].re)).toEqual([inlinePxFont]);
    });

    it('leaves rem font-sizes alone, since those scale with the root', () => {
        expect(RULES.some((rule) => remFontSize.match(rule.re) !== null)).toBe(
            false,
        );
    });

    /**
     * shareCard.ts draws onto a fixed-size raster, where a px font is correct.
     * The inline rule matches a `fontSize` property, never a canvas font string.
     */
    it('leaves canvas ctx.font px strings alone', () => {
        expect(RULES.some((rule) => canvasFont.match(rule.re) !== null)).toBe(
            false,
        );
    });

    /**
     * F2 removed the off-scale-radius rule after tokening the rest of
     * Tailwind's radius keywords (--radius-2xl/3xl/4xl joined app.css's
     * @theme static). This is the regression test for that removal: exactly
     * two rules remain, and rounded-2xl/3xl/4xl are no longer matched by
     * anything — proving the rule went away because it ran out of a
     * violation to find, not because RULES was silently trimmed further.
     */
    it('has exactly the five rules the docstring documents', () => {
        expect(RULES.map((rule) => rule.name)).toEqual([
            'raw Tailwind palette utility',
            'off-token shadow utility',
            'px font-size utility',
            'inline px font-size',
            'ground-fixed gradient stop',
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

describe('ground-fixed gradient stop', () => {
    // RULES[4] — appended, so the indices above stay put.
    const rule = RULES[4].re;

    it('flags a fixed-light token used as a gradient stop', () => {
        // The exact shape of MetricExplainer's popover before it was fixed:
        // a near-white gradient carrying near-white text, 1.00:1 on dark.
        expect(
            'bg-gradient-to-br from-surface-warm to-surface-elev'.match(rule),
        ).toEqual(['from-surface-warm', 'to-surface-elev']);
    });

    it('flags the shorter fixed names too, not just the hyphenated ones', () => {
        expect('from-ink to-cream via-line'.match(rule)).toEqual([
            'from-ink',
            'to-cream',
            'via-line',
        ]);
    });

    it('leaves a ground-reactive stop alone', () => {
        expect(
            'bg-gradient-to-l from-popover to-transparent'.match(rule),
        ).toBeNull();
        expect(
            'bg-gradient-to-br from-horizon/34 to-horizon/10'.match(rule),
        ).toBeNull();
    });
});
