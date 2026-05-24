import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PageHero from './PageHero';

describe('PageHero', () => {
    it('renders eyebrow + lead + italic emph on a cream surface (default)', () => {
        render(<PageHero eyebrow="Hari Ini" lead="Setiap lari" emph="ada ceritanya." />);
        expect(screen.getByText('Hari Ini')).toBeInTheDocument();
        expect(screen.getByText('ada ceritanya.')).toBeInTheDocument();
    });

    it('omits the lead clause when only emph is provided', () => {
        render(<PageHero eyebrow="★ Identitas kamu" emph="Aku." />);
        expect(screen.getByText('Aku.')).toBeInTheDocument();
    });

    it('applies the on-sky tone classes (cream text + horizon accent)', () => {
        render(<PageHero onSky eyebrow="Koleksi" lead="Trophy wall," emph="kartu lari." />);
        const eyebrow = screen.getByText('Koleksi');
        expect(eyebrow.className).toContain('text-horizon');
    });
});
