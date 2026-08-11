import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { Rarity } from '@/types/inertia';

import KartuMini from './KartuMini';

const SAMPLE_POLYLINE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

describe('KartuMini', () => {
    it('renders name and date in the micro footer', () => {
        render(<KartuMini name="Sunset 5K" date="18 Mei" />);
        expect(screen.getByText('Sunset 5K')).toBeInTheDocument();
        expect(screen.getByText('18 Mei')).toBeInTheDocument();
    });

    it.each([
        'common',
        'uncommon',
        'rare',
        'epic',
        'legendary',
    ] satisfies Rarity[])('renders for rarity %s', (rarity) => {
        render(<KartuMini name="x" rarity={rarity} />);
        expect(screen.getByText('x')).toBeInTheDocument();
    });

    it('draws a route thumbnail when a polyline is present', () => {
        const { container } = render(
            <KartuMini name="x" polyline={SAMPLE_POLYLINE} />,
        );
        expect(
            container.querySelector('[data-variant="route"]'),
        ).not.toBeNull();
    });

    it('renders the edition mark when provided', () => {
        render(<KartuMini name="x" edition={{ index: 2, total: 5 }} />);
        expect(screen.getByText('#2/5')).toBeInTheDocument();
    });

    it('joins edition and date with a separator when both are provided', () => {
        render(
            <KartuMini
                name="x"
                date="18 Mei"
                edition={{ index: 2, total: 5 }}
            />,
        );
        expect(screen.getByText('#2/5')).toBeInTheDocument();
        expect(screen.getByText('18 Mei')).toBeInTheDocument();
        expect(screen.getByText('·')).toBeInTheDocument();
    });

    it('renders the TemariProto mascot in the art zone', () => {
        const { container } = render(<KartuMini name="x" />);
        // Art zone always contains the mascot SVG.
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it.each([
        ['common', 1],
        ['uncommon', 2],
        ['rare', 3],
        ['epic', 4],
        ['legendary', 5],
    ] satisfies [Rarity, number][])(
        'scales the thread-band accent stitch count for %s',
        (rarity, count) => {
            const { container } = render(
                <KartuMini name="x" rarity={rarity} />,
            );
            // Scoped to the thread-band's own <div aria-hidden> wrapper — the
            // mascot glyph in the art zone (a <span aria-hidden>) also draws
            // its own unrelated <line> elements.
            expect(
                container.querySelectorAll('div[aria-hidden] line').length,
            ).toBe(count);
        },
    );
});
