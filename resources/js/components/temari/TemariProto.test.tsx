import { ITEMS } from '@brand/build-accessories.mjs';
import {
    BOUNDS,
    SLOT_NAMES,
    STATE_NAMES,
    mascot,
} from '@brand/build-mascot.mjs';
import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TemariProto, {
    TEMARI_EXPRESSIONS,
    type TemariEquipped,
    type TemariExpression,
    type TemariPose,
} from './TemariProto';

const LEGACY_POSES: TemariPose[] = [
    'proud',
    'pumped',
    'excited',
    'holding',
    'reading',
    'wobble',
    'observational',
    'glow',
];

// Attributes that carry geometry or colour. Anything else (data-*, ids, the
// clip reference) differs between a standalone brand SVG and the app tree by
// design and is not part of what has to stay in sync.
const SHAPE_ATTRS: Record<string, string[]> = {
    path: ['d', 'fill', 'stroke', 'stroke-width'],
    circle: ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width', 'opacity'],
    ellipse: [
        'cx',
        'cy',
        'rx',
        'ry',
        'fill',
        'stroke',
        'stroke-width',
        'opacity',
    ],
    rect: ['x', 'y', 'width', 'height', 'fill', 'opacity'],
};

function serialize(tag: string, read: (name: string) => string | null): string {
    const attrs = SHAPE_ATTRS[tag] ?? [];
    return `${tag}[${attrs.map((name) => `${name}=${read(name) ?? ''}`).join(',')}]`;
}

function shapesFromMarkup(svg: string): string[] {
    return [...svg.matchAll(/<(path|circle|ellipse|rect)\b([^>]*)>/g)].map(
        ([, tag, body]) =>
            serialize(
                tag,
                (name) =>
                    new RegExp(`\\s${name}="([^"]*)"`).exec(body)?.[1] ?? null,
            ),
    );
}

function shapesFromDom(container: HTMLElement): string[] {
    return Array.from(
        container.querySelectorAll<SVGElement>('path, circle, ellipse, rect'),
    ).map((el) =>
        serialize(el.tagName.toLowerCase(), (name) => el.getAttribute(name)),
    );
}

function paintedColors(container: HTMLElement): Set<string> {
    const colors = new Set<string>();
    for (const el of Array.from(container.querySelectorAll<SVGElement>('*'))) {
        for (const name of ['fill', 'stroke']) {
            const value = el.getAttribute(name);
            if (value !== null) {
                colors.add(value);
            }
        }
    }
    return colors;
}

/** unlock key → the TemariEquipped slot/variant lib/equippedAccessories.ts maps it to. */
const VARIANT_SOURCE: Array<[keyof TemariEquipped, string, string]> = [
    ['headband', 'ember', 'accessory.headband_uncommon'],
    ['headband', 'epik', 'accessory.headband_epic'],
    ['headband', 'legendaris', 'accessory.headband_legendary'],
    ['medal', 'pertama', 'accessory.medal_first'],
    ['medal', 'perak', 'accessory.medal_silver'],
    ['medal', 'emas', 'accessory.medal_gold'],
    ['medal', 'platina', 'accessory.medal_platinum'],
    ['kaus', 'pemula', 'accessory.shirt_beginner'],
    ['kaus', 'pagi', 'accessory.shirt_early_bird'],
    ['kaus', 'hujan', 'accessory.shirt_rain_warrior'],
    ['kaus', 'legendaris', 'accessory.shirt_legendary'],
    ['celana', 'ringan', 'accessory.shorts_lightweight'],
    ['celana', 'jarak', 'accessory.shorts_explorer'],
    ['celana', 'split', 'accessory.shorts_negative_split'],
    ['celana', 'maraton', 'accessory.shorts_marathon'],
    ['sepatu', 'basic', 'accessory.shoes_basic'],
    ['sepatu', 'cepat', 'accessory.shoes_speed'],
    ['sepatu', 'tahan', 'accessory.shoes_rugged'],
    ['sepatu', 'legendaris', 'accessory.shoes_legendary'],
    ['aura', 'pemanasan', 'accessory.aura_warmup'],
    ['aura', 'gerah', 'accessory.aura_heatwave'],
    ['aura', 'tenang', 'accessory.aura_calm'],
    ['aura', 'jagoan', 'accessory.aura_champion'],
    ['aura', 'angin', 'accessory.aura_windrunner'],
];

const COLOR_BY_KEY: Record<string, string> = Object.fromEntries(
    (ITEMS as Array<{ key: string; colour: string }>).map((item) => [
        item.key,
        item.colour,
    ]),
);

describe('TemariProto — parity with the brand generator', () => {
    it('exposes exactly the states build-mascot.mjs draws', () => {
        expect([...TEMARI_EXPRESSIONS]).toEqual(STATE_NAMES);
    });

    it.each(TEMARI_EXPRESSIONS)(
        'draws the %s face with the generator geometry',
        (expression) => {
            const { container } = render(
                <TemariProto pose={expression} dropShadow={false} />,
            );
            expect(shapesFromDom(container)).toEqual(
                shapesFromMarkup(mascot(expression)),
            );
        },
    );

    it('covers every wearable slot the generator defines', () => {
        const equipped: TemariEquipped = {
            headband: 'legendaris',
            kaus: 'hujan',
            celana: 'split',
            sepatu: 'tahan',
            medal: 'emas',
            aura: 'jagoan',
        };
        const { container } = render(<TemariProto equipped={equipped} />);
        const worn = render(
            <TemariProto equipped={{ medal: 'none' }} />,
        ).container;

        expect(SLOT_NAMES).toHaveLength(6);
        expect(shapesFromDom(container).length).toBeGreaterThan(
            shapesFromDom(worn).length,
        );
    });

    it.each(VARIANT_SOURCE)(
        'paints %s/%s with the swept catalogue colour',
        (slot, variant, key) => {
            const { container } = render(
                <TemariProto equipped={{ [slot]: variant }} />,
            );
            expect([...paintedColors(container)]).toContain(COLOR_BY_KEY[key]);
        },
    );

    it('places the character so the exported bounds land inside the viewBox', () => {
        const { container } = render(
            <TemariProto pose="celebrating" equipped={{ aura: 'jagoan' }} />,
        );
        const transform = container
            .querySelector('svg g[transform]')
            ?.getAttribute('transform');
        const parsed =
            /^translate\((-?[\d.]+) (-?[\d.]+)\) scale\(([\d.]+)\)$/.exec(
                transform ?? '',
            );
        expect(parsed).not.toBeNull();

        const [tx, ty, scale] = parsed!.slice(1).map(Number);
        const { outer, bottom: gearBottom } = BOUNDS.withAccessories;
        const at = (v: number, offset: number) => offset + v * scale;

        expect(at(50 - outer, tx)).toBeCloseTo(0, 2);
        expect(at(50 + outer, tx)).toBeCloseTo(120, 2);
        // The halo top is the edge a naive scale/translate loses first.
        expect(at(BOUNDS.top, ty)).toBeGreaterThanOrEqual(-4);
        expect(at(52 - outer, ty)).toBeGreaterThanOrEqual(-4);
        expect(
            at(Math.max(BOUNDS.bottom, gearBottom, 52 + outer), ty),
        ).toBeLessThanOrEqual(136);
    });
});

describe('TemariProto', () => {
    it.each([...LEGACY_POSES, ...TEMARI_EXPRESSIONS])(
        'renders without crashing for pose %s',
        (pose) => {
            const { container } = render(<TemariProto pose={pose} />);
            expect(container.querySelector('svg')).toBeInTheDocument();
            expect(container.firstChild).toHaveAttribute('data-pose', pose);
            expect(container.firstChild).toHaveAttribute('data-expression');
        },
    );

    it.each([
        ['proud', 'pleased'],
        ['pumped', 'hyped'],
        ['excited', 'impressed'],
        ['holding', 'resting'],
        ['reading', 'resting'],
        ['wobble', 'concerned'],
        ['observational', 'resting'],
        ['glow', 'celebrating'],
    ] as Array<[TemariPose, TemariExpression]>)(
        'resolves the legacy %s pose to the %s face',
        (pose, expression) => {
            const { container } = render(<TemariProto pose={pose} />);
            expect(container.firstChild).toHaveAttribute(
                'data-expression',
                expression,
            );
        },
    );

    it('disables animation when animate=false', () => {
        const { container } = render(
            <TemariProto pose="proud" animate={false} />,
        );
        const root = container.firstChild as HTMLElement;
        expect(root.style.animation).toBe('none');
    });

    it('accepts a custom animation string', () => {
        const { container } = render(
            <TemariProto pose="proud" animate="custom 1s linear" />,
        );
        const root = container.firstChild as HTMLElement;
        expect(root.style.animation).toContain('custom 1s linear');
    });

    it('gives every pose its own CSS animation when animate is true', () => {
        for (const pose of [...LEGACY_POSES, ...TEMARI_EXPRESSIONS]) {
            const { container } = render(<TemariProto pose={pose} animate />);
            const root = container.firstChild as HTMLElement;
            expect(root.style.animation).toContain('temari-');
        }
    });

    it('respects the size prop on the outer wrapper', () => {
        const { container } = render(<TemariProto size={200} />);
        const outer = container.firstChild as HTMLElement;
        expect(outer.style.width).toBe('200px');
    });

    it('keeps the viewBox every call site already reserves space for', () => {
        const { container } = render(<TemariProto />);
        const svg = container.querySelector('svg');
        expect(svg?.getAttribute('viewBox')).toBe('0 -4 120 140');
    });

    it('applies the drop-shadow filter by default and omits it when dropShadow=false', () => {
        const withShadow = render(<TemariProto pose="proud" />);
        expect(
            withShadow.container.querySelector(
                'g[filter="url(#temari-shadow)"]',
            ),
        ).toBeInTheDocument();

        const noShadow = render(
            <TemariProto pose="proud" dropShadow={false} />,
        );
        expect(
            noShadow.container.querySelector('g[filter="url(#temari-shadow)"]'),
        ).not.toBeInTheDocument();
    });

    it('outlines the body in cream on a sky surface and in sky on cream', () => {
        const onSky = render(<TemariProto tone="sky" />).container;
        const onCream = render(<TemariProto tone="cream" />).container;
        expect(
            onSky.querySelector('[data-part="body"]')?.getAttribute('stroke'),
        ).toBe('#f5f0e4');
        expect(
            onCream.querySelector('[data-part="body"]')?.getAttribute('stroke'),
        ).toBe('#241c54');
    });

    it('skips the medal when equipped.medal is "none" or absent', () => {
        const none = render(<TemariProto equipped={{ medal: 'none' }} />);
        expect(none.container.querySelector('[data-slot="medal"]')).toBeNull();
        const bare = render(<TemariProto />);
        expect(bare.container.querySelector('[data-slot="medal"]')).toBeNull();
    });

    it('renders the medal when one is equipped', () => {
        const { container } = render(
            <TemariProto equipped={{ medal: 'emas' }} />,
        );
        expect(
            container.querySelector('[data-slot="medal"]'),
        ).toBeInTheDocument();
    });

    it('treats equipped.aura === true as the warm-up aura', () => {
        const flagged = render(
            <TemariProto equipped={{ aura: true }} />,
        ).container;
        const named = render(
            <TemariProto equipped={{ aura: 'pemanasan' }} />,
        ).container;
        expect(
            flagged.querySelector('[data-slot="aura"]')?.getAttribute('stroke'),
        ).toBe(
            named.querySelector('[data-slot="aura"]')?.getAttribute('stroke'),
        );
    });

    it('renders no aura when none is equipped', () => {
        const { container } = render(<TemariProto />);
        expect(container.querySelector('[data-slot="aura"]')).toBeNull();
    });

    it('falls back to the slot default for an unknown variant', () => {
        const unknown = render(
            <TemariProto
                equipped={{ kaus: 'bukan-varian' as TemariEquipped['kaus'] }}
            />,
        ).container;
        expect([...paintedColors(unknown)]).toContain(
            COLOR_BY_KEY['accessory.shirt_beginner'],
        );
    });

    it('renders no thread coverage when no season phase is given', () => {
        const { container } = render(<TemariProto />);
        const bands = container.querySelectorAll('ellipse[transform]');
        expect(bands).toHaveLength(0);
    });

    it('renders denser thread coverage for peak than for base', () => {
        const countBands = (container: HTMLElement) =>
            container.querySelectorAll('ellipse[transform]').length;
        const base = render(<TemariProto seasonPhase="base" />).container;
        const build = render(<TemariProto seasonPhase="build" />).container;
        const peak = render(<TemariProto seasonPhase="peak" />).container;
        expect(countBands(build)).toBeGreaterThan(countBands(base));
        expect(countBands(peak)).toBeGreaterThan(countBands(build));
    });

    it('adds a rested shine for the taper phase without dropping coverage', () => {
        const countBands = (container: HTMLElement) =>
            container.querySelectorAll(
                'ellipse[transform]:not([data-season="shine"])',
            ).length;
        const peak = render(<TemariProto seasonPhase="peak" />).container;
        const taper = render(<TemariProto seasonPhase="taper" />).container;
        expect(countBands(taper)).toBe(countBands(peak));
        expect(peak.querySelector('[data-season="shine"]')).toBeNull();
        expect(
            taper.querySelector('[data-season="shine"]'),
        ).toBeInTheDocument();
    });
});
