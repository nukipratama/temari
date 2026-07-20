import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Kartu from './Kartu';
import type { Rarity } from '@/types/inertia';

const SAMPLE_POLYLINE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

describe('Kartu', () => {
    it('renders name and hero km', () => {
        render(<Kartu name="Pejuang Subuh" km="8.4" durasi="42:11" trimp={68} />);
        expect(screen.getByText('Pejuang Subuh')).toBeInTheDocument();
        expect(screen.getByText('8.4')).toBeInTheDocument();
    });

    it('shows duration in the stat row on the full tier', () => {
        render(<Kartu name="x" km="8.4" durasi="42:11" trimp={68} size="lg" />);
        // duration joins the stat row only on the full tier
        expect(screen.getByText(/42:11/)).toBeInTheDocument();
    });

    it('shows the TRIMP number in the floating badge', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={68} />);
        // TRIMP is rendered as a number in the TRIMPBadge, not "TRIMP 68"
        expect(screen.getByText('68')).toBeInTheDocument();
    });

    it.each(['common', 'uncommon', 'rare', 'epic', 'legendary'] satisfies Rarity[])(
        'renders the rarity set symbol for %s',
        (rarity) => {
            render(<Kartu name="x" km="1" durasi="1:00" trimp={1} rarity={rarity} />);
            const symbol = { common: '●', uncommon: '◆', rare: '★', epic: '✦', legendary: '✺' }[rarity];
            expect(screen.getByText(symbol)).toBeInTheDocument();
        },
    );

    it('renders the edition mark when provided', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={1} edition={{ index: 3, total: 12 }} />);
        expect(
            screen.getByText((_, el) => el?.tagName === 'SPAN' && el.textContent === '#3/12'),
        ).toBeInTheDocument();
    });

    it('draws the route glyph when a polyline is present', () => {
        const { container } = render(
            <Kartu name="x" km="1" durasi="1:00" trimp={1} polyline={SAMPLE_POLYLINE} />,
        );
        expect(container.querySelector('[data-variant="route"]')).not.toBeNull();
        expect(container.querySelector('[data-variant="route"] path')).not.toBeNull();
    });

    it('renders the art zone at all sizes including compact', () => {
        // RouteGlyph always renders an SVG in the art zone (route path or its glyph fallback).
        const { container } = render(<Kartu name="x" km="1" durasi="1:00" trimp={1} size="md" />);
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('shows the route glyph fallback in the art zone when there is no route data', () => {
        const { container } = render(<Kartu name="x" km="1" durasi="1:00" trimp={1} size="lg" />);
        // Without polyline RouteGlyph falls back to the bunny glyph variant.
        expect(container.querySelector('[data-variant="glyph"]')).not.toBeNull();
    });

    // `flex-1` is `flex: 1 1 0%`, so inside the fixed aspect-[5/7] frame a tall
    // stat block could shrink the art window to nothing. That hid the route art
    // and dropped the window's bottom-left EditionMark onto the card's top-left
    // RarityChip, rendering as "BERKESAN4" on a 320px grid.
    it('keeps a floor under the art window so it cannot be squeezed to nothing', () => {
        const { container } = render(
            <Kartu name="x" km="1" durasi="1:00" trimp={1} polyline={SAMPLE_POLYLINE} />,
        );
        const artWindow = container.querySelector('[data-variant="route"]')?.closest('.relative');

        expect(artWindow?.className).toContain('min-h-[30%]');
    });

    // Six pips wrap over four rows on a ~140px grid card, which is what pushed
    // the stat block past the fixed-aspect frame.
    it('caps a grid thumbnail at a single badge pip', () => {
        render(
            <Kartu
                name="x"
                km="1"
                durasi="1:00"
                trimp={1}
                badges={['negative_split', 'rajin', 'keras', 'berturut']}
                size="md"
                compact
            />,
        );

        expect(screen.getByText('Negative Split')).toBeInTheDocument();
        // The rest are dropped; the detail view still shows every badge.
        expect(screen.queryByText('Rajin')).not.toBeInTheDocument();
        expect(screen.queryByText('Keras')).not.toBeInTheDocument();
        expect(screen.queryByText('Berturut')).not.toBeInTheDocument();
    });

    it('shows every badge when not a compact thumbnail', () => {
        render(
            <Kartu
                name="x"
                km="1"
                durasi="1:00"
                trimp={1}
                badges={['negative_split', 'rajin', 'keras', 'berturut']}
                size="md"
            />,
        );

        expect(screen.getByText('Berturut')).toBeInTheDocument();
    });

    it('renders badge pips at the compact (md) size too (same full block as the share card)', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={1} badges={['negative_split']} size="md" />);
        expect(screen.getByText('Negative Split')).toBeInTheDocument();
    });

    it('shows badge pips (name only, no description) in the art overlay at the full (lg) size', () => {
        render(
            <Kartu
                name="x"
                km="1"
                durasi="1:00"
                trimp={1}
                badges={['negative_split']}
                size="lg"
            />,
        );
        expect(screen.getByText('Negative Split')).toBeInTheDocument();
        // Description lives in the title attribute, not visible DOM text.
        expect(screen.queryByText(/malah lebih ngebut/)).toBeNull();
    });

    it('exposes the mood via the TRIMP badge aria-label', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={1} mood="nyala" size="lg" />);
        // Mood rides on the TRIMP "power" badge pip as an accessible label.
        expect(screen.getByLabelText('Vibe Nyala')).toBeInTheDocument();
    });

    it('shows a mood pip with aria-label but no visible label text on the compact tier', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={1} mood="lemes" size="md" />);
        expect(screen.getByLabelText('Vibe Lemes')).toBeInTheDocument();
        expect(screen.queryByText('Lemes')).toBeNull();
    });

    it('shows a labeled stat grid on the full tier', () => {
        render(
            <Kartu
                name="x"
                km="1"
                durasi="1:00"
                trimp={1}
                size="lg"
                stats={{ pace: '5:30/km', hr: '150 bpm', cadence: '178 spm', fastestKm: '5:02/km' }}
            />,
        );
        // The full tier is a dense, labeled TCG stat block.
        expect(screen.getByText('Pace')).toBeInTheDocument();
        expect(screen.getByText('5:30/km')).toBeInTheDocument();
        expect(screen.getByText('150 bpm')).toBeInTheDocument();
        expect(screen.getByText('Cadence')).toBeInTheDocument();
        expect(screen.getByText('178 spm')).toBeInTheDocument();
        expect(screen.getByText('Best')).toBeInTheDocument();
    });

    it('renders the HR-zone effort bar when zone data is present', () => {
        const { container } = render(
            <Kartu name="x" km="1" durasi="1:00" trimp={1} size="lg" zonePct={{ Z1: 20, Z2: 50, Z3: 30 }} />,
        );
        // The bar segments carry per-zone titles. The card uses the bare bar now
        // (no Z1..Z5 legend), matching the share card.
        expect(container.querySelector('[title="Z2: 50%"]')).not.toBeNull();
        expect(screen.queryByText('Z1')).toBeNull();
    });

    it('omits the HR-zone bar when there is no zone data', () => {
        const { container } = render(<Kartu name="x" km="1" durasi="1:00" trimp={1} size="lg" />);
        expect(container.querySelector('[title^="Z"]')).toBeNull();
    });

    it('shows the full stat grid (pace, HR, duration) at the compact tier too', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={1} size="md" stats={{ pace: '5:30/km', hr: '150 bpm' }} />);
        // md now renders the same full stat block as the share card, duration included.
        expect(screen.getByText(/5:30\/km/)).toBeInTheDocument();
        expect(screen.getByText(/150 bpm/)).toBeInTheDocument();
        expect(screen.getByText(/1:00/)).toBeInTheDocument();
    });

    it('renders the badge emoji pip in the art overlay at the full (lg) size', () => {
        render(
            <Kartu
                name="x"
                km="1"
                durasi="1:00"
                trimp={1}
                badges={['negative_split']}
                size="lg"
            />,
        );
        expect(screen.getByText('👻')).toBeInTheDocument();
    });

    it('falls back to the slug name as the pip title when a badge has no ability', () => {
        // An unknown slug is in neither BADGE_ABILITY nor BADGE_LABELS, so the
        // title is just the prettified name (no " · ability" suffix).
        render(
            <Kartu name="x" km="1" durasi="1:00" trimp={1} badges={['mystery_move']} size="lg" />,
        );
        const pip = screen.getByText('Mystery Move').closest('span[title]');
        expect(pip).toHaveAttribute('title', 'Mystery Move');
    });

    it('omits the full-tier stat grid when no stat values are present', () => {
        // With no stats and a blank duration, every grid cell is filtered out so
        // StatGrid renders nothing (returns null).
        render(<Kartu name="x" km="1" durasi="" trimp={1} size="lg" />);
        expect(screen.queryByText('Pace')).toBeNull();
        expect(screen.queryByText('Durasi')).toBeNull();
    });

    it('hides the stat grid below the sm breakpoint (not the DOM) when hideStats is set', () => {
        // A narrow mobile grid tile has no room for the dense labeled stat block —
        // `hideStats` wraps it in `hidden sm:block` (CSS, so wider grid columns at
        // sm+ still reveal it) rather than stripping it from the tree.
        render(
            <Kartu
                name="x"
                km="1"
                durasi="1:00"
                trimp={1}
                size="md"
                hideStats
                stats={{ pace: '5:30/km', hr: '150 bpm' }}
            />,
        );
        const paceCell = screen.getByText('5:30/km').closest('div.min-w-0');
        const wrapper = paceCell?.closest('dl')?.parentElement;
        expect(wrapper).toHaveClass('hidden', 'sm:block');
    });

});
