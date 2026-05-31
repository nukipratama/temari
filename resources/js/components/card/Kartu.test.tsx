import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Kartu from './Kartu';
import type { Rarity } from '@/types/inertia';

// Classic Google-encoded polyline sample (decodes to several points).
const SAMPLE_POLYLINE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

describe('Kartu', () => {
    it('renders name, hero km, duration and demoted TRIMP', () => {
        render(<Kartu name="Pejuang Subuh" km="8.4" durasi="42:11" trimp={68} />);
        expect(screen.getByText('Pejuang Subuh')).toBeInTheDocument();
        expect(screen.getByText('8.4')).toBeInTheDocument();
        expect(screen.getByText('42:11')).toBeInTheDocument();
        expect(screen.getByText('TRIMP 68')).toBeInTheDocument();
    });

    it.each(['common', 'uncommon', 'rare', 'epic', 'legendary'] satisfies Rarity[])(
        'renders the rarity ribbon for %s',
        (rarity) => {
            render(<Kartu name="x" km="1" durasi="1:00" trimp={1} rarity={rarity} />);
            const label = { common: 'Biasa', uncommon: 'Berkesan', rare: 'Langka', epic: 'Luar Biasa', legendary: 'Legendaris' }[rarity];
            expect(screen.getByText(label)).toBeInTheDocument();
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

    it('collapses the art window on the compact tier when there is no route or pace', () => {
        const { container } = render(<Kartu name="x" km="1" durasi="1:00" trimp={1} size="md" />);
        expect(container.querySelector('[data-variant]')).toBeNull();
    });

    it('shows the rarity motif on the full tier when there is no route or pace', () => {
        const { container } = render(<Kartu name="x" km="1" durasi="1:00" trimp={1} size="lg" />);
        expect(container.querySelector('[data-variant="glyph"]')).not.toBeNull();
    });

    it('shows compact emblem chips (no ability text) at md size', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={1} badges={['negative_split']} size="md" />);
        expect(screen.getByText('Negative Split')).toBeInTheDocument();
        expect(screen.queryByText(/malah lebih ngebut/)).toBeNull();
    });

    it('shows ability rows with descriptions at the full (lg) size', () => {
        render(<Kartu name="x" km="1" durasi="1:00" trimp={1} badges={['negative_split']} size="lg" />);
        expect(screen.getByText('Negative Split')).toBeInTheDocument();
        expect(screen.getByText(/malah lebih ngebut/)).toBeInTheDocument();
    });

    it('shows the flavor footer only on the full tier', () => {
        const { rerender } = render(
            <Kartu name="x" km="1" durasi="1:00" trimp={1} flavor="Comeback paruh kedua." size="md" />,
        );
        expect(screen.queryByText(/Comeback paruh kedua/)).toBeNull();
        rerender(<Kartu name="x" km="1" durasi="1:00" trimp={1} flavor="Comeback paruh kedua." size="lg" />);
        expect(screen.getByText(/Comeback paruh kedua/)).toBeInTheDocument();
    });
});
