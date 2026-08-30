import { describe, expect, it } from 'vitest';

import GROUND_KINDS from '../../brand/grounds.json';
import {
    auditPanels,
    auditContrast,
    auditSurface,
    collectPaperGrounds,
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

function groundRule(selectorText: string, surface: string) {
    return {
        selectorText,
        style: {
            getPropertyValue: (name: string) =>
                name === '--color-surface' ? surface : '',
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

describe('collectPaperGrounds', () => {
    const paperValues = Object.fromEntries(
        GROUND_KINDS.paper.map((name, i) => [
            `--color-${name}`,
            `#00000${i}`.slice(0, 7),
        ]),
    );

    it('carries every classified paper ground alongside the dawn-shift ones', () => {
        const grounds = collectPaperGrounds(
            [
                {
                    cssRules: [
                        {
                            cssRules: [
                                groundRule(
                                    "body[data-time-of-day='dawn']",
                                    '#f0ebdb',
                                ),
                                groundRule(
                                    "body[data-time-of-day='night']",
                                    '#eee8d9',
                                ),
                            ],
                        },
                    ],
                },
            ],
            paperValues,
        );

        expect(grounds.map((g) => g.name)).toEqual([
            ...GROUND_KINDS.paper,
            'surface · dawn',
            'surface · night',
        ]);
    });

    it('reaches past --color-surface and its dawn-shift drifts', () => {
        // The S2.9 blind spot in one assertion: cream-deep is the ground
        // AppShell paints and the one the old scrape could never see.
        const grounds = collectPaperGrounds([], paperValues);

        expect(grounds.map((g) => g.name)).toContain('cream-deep');
    });

    it('keeps a classified ground the stylesheet no longer resolves, so it scores as a failure', () => {
        const grounds = collectPaperGrounds([], {});

        expect(grounds.every((g) => g.value === '')).toBe(true);
        expect(grounds).not.toHaveLength(0);
    });

    it('ignores a time-of-day rule that does not redeclare the surface', () => {
        const grounds = collectPaperGrounds(
            [
                {
                    cssRules: [groundRule("body[data-time-of-day='dusk']", '')],
                },
            ],
            paperValues,
        );

        expect(grounds.map((g) => g.name)).toEqual(GROUND_KINDS.paper);
    });

    it('skips a cross-origin sheet instead of throwing', () => {
        const grounds = collectPaperGrounds(
            [
                {
                    get cssRules(): never {
                        throw new Error('SecurityError');
                    },
                },
            ],
            paperValues,
        );

        expect(grounds.map((g) => g.name)).toEqual(GROUND_KINDS.paper);
    });
});

describe('auditContrast', () => {
    const paper = { '--color-surface': '#f5f0e4' };
    const day = [{ name: 'day', value: '#f5f0e4' }];
    const allDay = [...day, { name: 'night', value: '#eee8d9' }];

    it('passes body text on paper and fails an ink that is too light', () => {
        const rows = auditContrast(
            {
                ...paper,
                '--color-ink': '#1a1812',
                '--color-ink-2': '#cccccc',
            },
            day,
        );

        expect(rows.find((r) => r.use === 'Body text')?.pass).toBe(true);
        expect(rows.find((r) => r.use === 'Secondary text')?.pass).toBe(false);
    });

    it('skips a pair whose tokens are not in the live set', () => {
        expect(auditContrast(paper, day)).toEqual([]);
    });

    it('checks a dark fill as a fill, and its -ink member as text', () => {
        const rows = auditContrast(
            {
                ...paper,
                '--color-mood-gassed': '#7a2030',
                '--color-mood-gassed-ink': '#7a2030',
            },
            day,
        );

        expect(rows.map((r) => r.use)).toEqual([
            'mood-gassed label',
            'mood-gassed fill',
        ]);
        expect(rows.every((r) => r.pass)).toBe(true);
        expect(rows[1].outlined).toBeUndefined();
    });

    it('tests the outline instead when a fill is too light to reach 3:1', () => {
        const rows = auditContrast(
            {
                ...paper,
                '--color-rarity-legendary': '#f5a623',
                '--color-rarity-legendary-ink': '#946415',
            },
            day,
        );
        const fill = rows.find((r) => r.use.endsWith('fill outline'));

        expect(fill?.outlined).toBe(true);
        expect(fill?.fg).toBe('--color-rarity-legendary-ink');
        expect(fill?.pass).toBe(true);
    });

    it('reports a paper pair at its worst ground, not at midday', () => {
        const values = {
            ...paper,
            '--color-horizon': '#d9a53c',
            '--color-horizon-ink': '#896826',
        };

        expect(
            auditContrast(values, day).find((r) => r.use === 'Gold as text'),
        ).toMatchObject({ bg: 'paper · day', pass: true });
        expect(
            auditContrast(values, allDay).find((r) => r.use === 'Gold as text'),
        ).toMatchObject({ bg: 'paper · night', pass: false });
    });

    it("scores a family's ink on its own tinted cell, not only on paper", () => {
        const values = {
            ...paper,
            '--color-mood-wobbly': '#b23a4f',
            '--color-mood-wobbly-bg': '#f0d3d8',
        };
        const label = (ink: string) =>
            auditContrast(
                { ...values, '--color-mood-wobbly-ink': ink },
                day,
            ).find((r) => r.use === 'mood-wobbly label');

        // Clears 5.11:1 on day paper, 4.16:1 on the cell it is actually printed
        // on. Scoring paper alone is what let it ship.
        expect(label('#b23a4f')).toMatchObject({
            bg: 'paper · mood-wobbly-bg',
            pass: false,
        });
        expect(label('#a9374b')?.pass).toBe(true);
    });

    it('scores an ink on its own alpha tint, not on the paper under it', () => {
        const values = {
            ...paper,
            '--color-horizon': '#d9a53c',
            '--color-horizon-ink': '#7e6023',
        };
        const onlyPaper = [{ name: 'cream-deep', value: '#ece2ce' }];
        const label = (ink: string) =>
            auditContrast(
                { ...values, '--color-horizon-ink': ink },
                onlyPaper,
            ).find((r) => r.use === 'horizon label');

        // #7e6023 clears the paper itself at 4.56:1 and still fails here: once
        // the bg-horizon/0.18 chip is composited over that paper it drops to
        // 4.14:1, and the chip is the ground the label is really printed on.
        expect(label('#7e6023')).toMatchObject({
            bg: 'paper · horizon/0.18 on paper',
            pass: false,
        });
        expect(label('#775a21')?.pass).toBe(true);
    });

    it('leaves a pair whose ground is not paper on its own background', () => {
        const rows = auditContrast(
            {
                ...paper,
                '--color-cream': '#f5f0e4',
                '--color-sky': '#241c54',
            },
            allDay,
        );

        expect(rows.find((r) => r.use === 'Text on indigo')?.bg).toBe(
            '--color-sky',
        );
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

describe('auditPanels', () => {
    const VALUES: Record<string, string> = {
        '--color-sky': '#241c54',
        '--color-cream': '#f5f0e4',
        '--color-ink': '#1a1812',
        '--color-foreground': '#1a1812',
        '--color-ink-on-sky': '#b0a3c9',
        '--color-text-2': '#34373c',
    };
    const PAPER = [{ name: 'cream-deep', value: '#ece2ce' }];

    it('scores a panel on what it composites to, not on the fill it tints', () => {
        // cream-deep at 70% over cream-deep (registered as "paper" here) is
        // still cream-deep, so the panel keeps the contrast its own family
        // was designed for — the fill tint alone wouldn't tell you that.
        const overPaper = auditPanels(
            { ...VALUES, '--color-cream-deep': '#ece2ce' },
            PAPER,
        ).find((row) => row.bg.startsWith('cream-deep/0.7'));
        expect(overPaper?.bg).toContain('on cream-deep');
        expect(overPaper?.pass).toBe(true);
    });

    it('reads every registered panel out of grounds.json', () => {
        const registry = GROUND_KINDS.panel as Record<
            string,
            { over?: Record<string, string[]>; text: string[] }
        >;
        expect(Object.keys(registry).length).toBeGreaterThan(0);

        const scored = Object.values(registry).filter(
            (entry) => entry.text.length > 0 && entry.over !== undefined,
        );
        expect(scored.length).toBeGreaterThan(0);
        for (const entry of scored) {
            expect(Object.keys(entry.over ?? {}).length).toBeGreaterThan(0);
        }
    });

    it('skips a panel whose fill, text or mount token is not declared', () => {
        expect(auditPanels({}, PAPER)).toEqual([]);
    });
});
