import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import FeaturedCardHero from './FeaturedCardHero';

const baseProps = {
    eyebrow: '★ Kartu minggu ini',
    name: 'Langkah Mantap',
    rarity: 'rare' as const,
    km: '10.01',
    ctaHref: '/aktivitas/7',
    card: <div data-testid="kartu" />,
};

describe('FeaturedCardHero', () => {
    it('renders the name and a CTA link to ctaHref with the default label', () => {
        render(<FeaturedCardHero {...baseProps} />);
        expect(screen.getByText('Langkah Mantap')).toBeInTheDocument();
        const cta = screen.getByRole('link', { name: /lihat aktivitas/i });
        expect(cta).toHaveAttribute('href', '/aktivitas/7');
        // The CTA navigates, so it must be an anchor, not a button nested in one.
        expect(cta.tagName).toBe('A');
    });

    it('honors a custom ctaLabel', () => {
        render(<FeaturedCardHero {...baseProps} ctaLabel="Lihat detail lari" ctaHref="/aktivitas/3" />);
        const cta = screen.getByRole('link', { name: /lihat detail lari/i });
        expect(cta).toHaveAttribute('href', '/aktivitas/3');
    });

    it('renders the rarity·km catch line', () => {
        render(<FeaturedCardHero {...baseProps} />);
        expect(screen.getByText('★ Langka · 10.01 KM')).toBeInTheDocument();
    });

    it('renders the stat cells when stats and durasi are provided', () => {
        render(
            <FeaturedCardHero
                {...baseProps}
                stats={{ pace: '5:30/km', hr: '150 bpm', cadence: '178 spm', fastestKm: '5:02/km' }}
                durasi="42:11"
            />,
        );
        expect(screen.getByText('PACE')).toBeInTheDocument();
        expect(screen.getByText('5:30/km')).toBeInTheDocument();
        expect(screen.getByText('HR')).toBeInTheDocument();
        expect(screen.getByText('150 bpm')).toBeInTheDocument();
        expect(screen.getByText('CADENCE')).toBeInTheDocument();
        expect(screen.getByText('178 spm')).toBeInTheDocument();
        expect(screen.getByText('DURASI')).toBeInTheDocument();
        expect(screen.getByText('42:11')).toBeInTheDocument();
        expect(screen.getByText('BEST')).toBeInTheDocument();
        expect(screen.getByText('5:02/km')).toBeInTheDocument();
    });

    it('omits the stat <dl> entirely when no stats or durasi are provided', () => {
        const { container } = render(<FeaturedCardHero {...baseProps} />);
        expect(container.querySelector('dl')).toBeNull();
    });

    it('renders badge pips with emblem + name when badges are provided', () => {
        render(<FeaturedCardHero {...baseProps} badges={['negative_split']} />);
        expect(screen.getByText('👻')).toBeInTheDocument();
        expect(screen.getByText('Negative Split')).toBeInTheDocument();
    });

    it('renders the voice slot when provided', () => {
        render(<FeaturedCardHero {...baseProps} voice={<span>Temari bilang halo</span>} />);
        expect(screen.getByText('Temari bilang halo')).toBeInTheDocument();
    });
});
