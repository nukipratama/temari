import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PageHero from './PageHero';

describe('PageHero', () => {
    it('renders eyebrow + lead + italic emph on a cream surface (default)', () => {
        render(
            <PageHero eyebrow="Today" lead="Every run" emph="has a story." />,
        );
        expect(screen.getByText('Today')).toBeInTheDocument();
        expect(screen.getByText('has a story.')).toBeInTheDocument();
    });

    it('omits the lead clause when only emph is provided', () => {
        render(<PageHero eyebrow="★ Your identity" emph="Me." />);
        expect(screen.getByText('Me.')).toBeInTheDocument();
    });

    it('applies the on-sky tone classes (cream text + horizon accent)', () => {
        render(
            <PageHero
                onSky
                eyebrow="Collection"
                lead="Trophy wall,"
                emph="of cards."
            />,
        );
        const eyebrow = screen.getByText('Collection');
        expect(eyebrow.className).toContain('text-horizon');
    });
});
