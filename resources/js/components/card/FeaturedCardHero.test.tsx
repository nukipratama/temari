import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import FeaturedCardHero from './FeaturedCardHero';

const baseProps = {
    eyebrow: '★ Kartu minggu ini',
    name: 'Langkah Mantap',
    rarity: 'rare' as const,
    km: '10.01',
    ctaHref: '/kartu/7',
    card: <div data-testid="kartu" />,
};

describe('FeaturedCardHero', () => {
    it('renders the name and a CTA link to ctaHref with the default label', () => {
        render(<FeaturedCardHero {...baseProps} />);
        expect(screen.getByText('Langkah Mantap')).toBeInTheDocument();
        const cta = screen.getByRole('link', { name: /lihat kartu/i });
        expect(cta).toHaveAttribute('href', '/kartu/7');
        // The CTA navigates, so it must be an anchor, not a button nested in one.
        expect(cta.tagName).toBe('A');
    });

    it('honors a custom ctaLabel', () => {
        render(<FeaturedCardHero {...baseProps} ctaLabel="Lihat detail lari" ctaHref="/aktivitas/3" />);
        const cta = screen.getByRole('link', { name: /lihat detail lari/i });
        expect(cta).toHaveAttribute('href', '/aktivitas/3');
    });
});
