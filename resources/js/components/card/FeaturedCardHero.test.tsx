import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import FeaturedCardHero from './FeaturedCardHero';

const baseProps = {
    eyebrow: "★ This week's card",
    name: 'Steady Steps',
    rarity: 'rare' as const,
    km: '10.01',
    ctaHref: '/activities/7',
    card: <div data-testid="kartu" />,
};

describe('FeaturedCardHero', () => {
    it('renders the name and a CTA link to ctaHref with the default label', () => {
        render(<FeaturedCardHero {...baseProps} />);
        expect(screen.getByText('Steady Steps')).toBeInTheDocument();
        const cta = screen.getByRole('link', { name: /view activity/i });
        expect(cta).toHaveAttribute('href', '/activities/7');
        // The CTA navigates, so it must be an anchor, not a button nested in one.
        expect(cta.tagName).toBe('A');
    });

    it('honors a custom ctaLabel', () => {
        render(
            <FeaturedCardHero
                {...baseProps}
                ctaLabel="View run detail"
                ctaHref="/activities/3"
            />,
        );
        const cta = screen.getByRole('link', { name: /view run detail/i });
        expect(cta).toHaveAttribute('href', '/activities/3');
    });

    it('renders the rarity·km catch line', () => {
        render(<FeaturedCardHero {...baseProps} />);
        expect(screen.getByText('★ Rare · 10.01 KM')).toBeInTheDocument();
    });

    it('renders the stat cells when stats and duration are provided', () => {
        render(
            <FeaturedCardHero
                {...baseProps}
                stats={{
                    pace: '5:30/km',
                    hr: '150 bpm',
                    cadence: '178 spm',
                    fastestKm: '5:02/km',
                }}
                duration="42:11"
            />,
        );
        expect(screen.getByText('PACE')).toBeInTheDocument();
        expect(screen.getByText('5:30/km')).toBeInTheDocument();
        expect(screen.getByText('HR')).toBeInTheDocument();
        expect(screen.getByText('150 bpm')).toBeInTheDocument();
        expect(screen.getByText('CADENCE')).toBeInTheDocument();
        expect(screen.getByText('178 spm')).toBeInTheDocument();
        expect(screen.getByText('DURATION')).toBeInTheDocument();
        expect(screen.getByText('42:11')).toBeInTheDocument();
        expect(screen.getByText('BEST')).toBeInTheDocument();
        expect(screen.getByText('5:02/km')).toBeInTheDocument();
    });

    it('omits the stat <dl> entirely when no stats or duration are provided', () => {
        const { container } = render(<FeaturedCardHero {...baseProps} />);
        expect(container.querySelector('dl')).toBeNull();
    });

    it('renders badge pips with emblem + name when badges are provided', () => {
        render(<FeaturedCardHero {...baseProps} badges={['negative_split']} />);
        expect(screen.getByText('👻')).toBeInTheDocument();
        expect(screen.getByText('Negative Split')).toBeInTheDocument();
    });

    it('renders the voice slot when provided', () => {
        render(
            <FeaturedCardHero
                {...baseProps}
                voice={<span>Temari bilang halo</span>}
            />,
        );
        expect(screen.getByText('Temari bilang halo')).toBeInTheDocument();
    });

    it('renders the route watermark when a polyline is provided', () => {
        const { container } = render(
            <FeaturedCardHero {...baseProps} polyline="abc123" />,
        );
        expect(
            container.querySelector('[data-variant="route"]'),
        ).not.toBeNull();
    });

    it('omits the route watermark when no polyline is provided', () => {
        const { container } = render(<FeaturedCardHero {...baseProps} />);
        expect(container.querySelector('[data-variant="route"]')).toBeNull();
    });
});
