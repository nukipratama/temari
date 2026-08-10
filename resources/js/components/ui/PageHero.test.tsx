import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PageHero from './PageHero';

describe('PageHero', () => {
    it('renders the eyebrow and headline children', () => {
        render(
            <PageHero eyebrow="Today">
                Every run <em>has a story.</em>
            </PageHero>,
        );
        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('has a story.')).toBeInTheDocument();
    });

    it('applies the requested display-scale step', () => {
        render(<PageHero size="2xl">Hey, runner</PageHero>);
        expect(screen.getByText('Hey, runner').className).toContain(
            'text-display-2xl',
        );
    });

    it('defaults to the lg step and ink text on a cream surface', () => {
        render(<PageHero>Plain headline</PageHero>);
        const h1 = screen.getByText('Plain headline');
        expect(h1.className).toContain('text-display-lg');
        expect(h1.className).toContain('text-ink');
    });

    it('applies the on-sky tone (cream headline text)', () => {
        render(<PageHero onSky>Trophy wall</PageHero>);
        expect(screen.getByText('Trophy wall').className).toContain(
            'text-cream',
        );
    });

    it('italicizes the whole headline when requested', () => {
        render(<PageHero italic>Your heart rate zones.</PageHero>);
        expect(screen.getByText('Your heart rate zones.').className).toContain(
            'italic',
        );
    });
});
