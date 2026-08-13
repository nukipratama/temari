import { describe, expect, it } from 'vitest';

import {
    auditContrast,
    auditSurface,
    collectTokenNames,
    contrastRatio,
    groupColorFamilies,
    luminance,
    normalizeShadow,
    readTokenValues,
    tokensWithPrefix,
} from './designTokens';

function styleRule(selectorText: string, properties: string[]) {
    return {
        selectorText,
        style: {
            length: properties.length,
            item: (i: number) => properties[i],
        } as unknown as CSSStyleDeclaration,
    };
}

describe('collectTokenNames', () => {
    it('reads custom properties off :root rules', () => {
        const names = collectTokenNames([
            { cssRules: [styleRule(':root', ['--color-ink', 'color'])] },
        ]);

        expect(names).toEqual(['--color-ink']);
    });

    it('descends into @layer / @media blocks, where Tailwind emits the theme', () => {
        const names = collectTokenNames([
            {
                cssRules: [
                    {
                        cssRules: [
                            styleRule(':root, :host', ['--radius-md']),
                            styleRule('.card', ['--ignored']),
                        ],
                    },
                ],
            },
        ]);

        expect(names).toEqual(['--radius-md']);
    });

    it('skips a cross-origin sheet instead of throwing', () => {
        const names = collectTokenNames([
            {
                get cssRules(): never {
                    throw new Error('SecurityError');
                },
            },
            { cssRules: [styleRule(':root', ['--color-sky'])] },
        ]);

        expect(names).toEqual(['--color-sky']);
    });

    it('de-duplicates and sorts', () => {
        const names = collectTokenNames([
            { cssRules: [styleRule(':root', ['--b', '--a'])] },
            { cssRules: [styleRule(':root', ['--a'])] },
        ]);

        expect(names).toEqual(['--a', '--b']);
    });
});

describe('readTokenValues', () => {
    it('resolves each name against the element and drops the undeclared ones', () => {
        const element = document.createElement('div');
        element.style.setProperty('--color-ink', '#1a1812');
        document.body.append(element);

        const values = readTokenValues(
            ['--color-ink', '--color-missing'],
            element,
        );

        expect(values).toEqual({ '--color-ink': '#1a1812' });
        element.remove();
    });
});

describe('tokensWithPrefix', () => {
    it('filters by namespace', () => {
        expect(
            tokensWithPrefix(['--radius-md', '--color-ink'], '--radius-'),
        ).toEqual(['--radius-md']);
    });
});

describe('groupColorFamilies', () => {
    it('buckets colour tokens by their family segment', () => {
        expect(
            groupColorFamilies([
                '--color-ink',
                '--color-ink-2',
                '--color-rarity-epic',
                '--radius-md',
            ]),
        ).toEqual([
            ['ink', ['--color-ink', '--color-ink-2']],
            ['rarity', ['--color-rarity-epic']],
        ]);
    });
});

describe('contrast maths', () => {
    it('returns 21 for black on white and 1 for a colour on itself', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
        expect(contrastRatio('#d9a53c', '#d9a53c')).toBeCloseTo(1, 5);
    });

    it('accepts short hex, hex with alpha, and rgb()', () => {
        expect(luminance('#fff')).toBeCloseTo(1, 5);
        expect(luminance('#ffffffcc')).toBeCloseTo(1, 5);
        expect(luminance('rgb(255, 255, 255)')).toBeCloseTo(1, 5);
    });

    it('returns null for a value it cannot parse', () => {
        expect(luminance('oklch(0.7 0.1 40)')).toBeNull();
        expect(contrastRatio('nope', '#ffffff')).toBeNull();
    });
});

describe('auditContrast', () => {
    const paper = { '--color-surface': '#f5f0e4' };

    it('passes body text on paper and fails an ink that is too light', () => {
        const rows = auditContrast({
            ...paper,
            '--color-ink': '#1a1812',
            '--color-ink-2': '#cccccc',
        });

        expect(rows.find((r) => r.use === 'Body text')?.pass).toBe(true);
        expect(rows.find((r) => r.use === 'Secondary text')?.pass).toBe(false);
    });

    it('skips a pair whose tokens are not in the live set', () => {
        expect(auditContrast(paper)).toEqual([]);
    });

    it('checks a dark fill as a fill, and its -ink member as text', () => {
        const rows = auditContrast({
            ...paper,
            '--color-mood-gassed': '#7a2030',
            '--color-mood-gassed-ink': '#7a2030',
        });

        expect(rows.map((r) => r.use)).toEqual([
            'mood-gassed label',
            'mood-gassed fill',
        ]);
        expect(rows.every((r) => r.pass)).toBe(true);
        expect(rows[1].outlined).toBeUndefined();
    });

    it('tests the outline instead when a fill is too light to reach 3:1', () => {
        const rows = auditContrast({
            ...paper,
            '--color-rarity-legendary': '#f5a623',
            '--color-rarity-legendary-ink': '#946415',
        });
        const fill = rows.find((r) => r.use.endsWith('fill outline'));

        expect(fill?.outlined).toBe(true);
        expect(fill?.fg).toBe('--color-rarity-legendary-ink');
        expect(fill?.pass).toBe(true);
    });
});

describe('normalizeShadow', () => {
    it('drops the transparent ring placeholders a shadow utility composes through', () => {
        expect(
            normalizeShadow(
                'rgba(0, 0, 0, 0) 0px 0px 0px 0px, rgba(0, 0, 0, 0) 0px 0px 0px 0px, rgba(58, 45, 20, 0.06) 0px 1px 2px 0px',
            ),
        ).toBe('rgba(58, 45, 20, 0.06) 0px 1px 2px 0px');
    });
});

describe('auditSurface', () => {
    const reference = { radii: ['14px', '18px'], shadows: ['0 1px 2px red'] };

    it('matches a utility-composed shadow against the token it came from', () => {
        expect(
            auditSurface(
                'card',
                {
                    borderRadius: '14px',
                    boxShadow:
                        'rgba(0, 0, 0, 0) 0px 0px 0px 0px, 0 1px 2px red',
                },
                reference,
            ).shadowOnScale,
        ).toBe(true);
    });

    it('accepts a surface that lands on both scales', () => {
        expect(
            auditSurface(
                'card',
                { borderRadius: '14px', boxShadow: '0  1px   2px red' },
                reference,
            ),
        ).toMatchObject({ radiusOnScale: true, shadowOnScale: true });
    });

    it('flags a radius that is not a step on the scale', () => {
        expect(
            auditSurface(
                'card',
                { borderRadius: '16px', boxShadow: 'none' },
                reference,
            ),
        ).toMatchObject({ radiusOnScale: false, shadowOnScale: true });
    });

    it('flags a hand-rolled shadow', () => {
        expect(
            auditSurface(
                'card',
                { borderRadius: '18px', boxShadow: '0 2px 9px black' },
                reference,
            ).shadowOnScale,
        ).toBe(false);
    });
});
