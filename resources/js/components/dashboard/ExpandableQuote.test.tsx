import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ExpandableQuote from './ExpandableQuote';

describe('ExpandableQuote', () => {
    it('renders the quoted text', () => {
        render(<ExpandableQuote text="Lari santai aja." />);
        expect(screen.getByText(/Lari santai aja\./)).toBeInTheDocument();
    });

    // Narration that opens by quoting a card name would otherwise collide with
    // the decorative frame and render as a doubled opening quote.
    it('strips an inner opening quote so the decorative frame is the only one', () => {
        render(<ExpandableQuote text={'"Full Send" sekali seumur progres, rayain.'} />);
        const paragraph = screen.getByText(/Full Send/);

        expect(paragraph.textContent).toBe('“Full Send sekali seumur progres, rayain.”');
    });

    it('leaves a mid-string quote alone (e.g. a pace like 5\'30")', () => {
        render(<ExpandableQuote text={'Pace 5\'30" rapi banget.'} />);
        const paragraph = screen.getByText(/Pace/);

        expect(paragraph.textContent).toBe('“Pace 5\'30" rapi banget.”');
    });

    it('omits the toggle for a short quote (<= 150 chars)', () => {
        render(<ExpandableQuote text="pendek" />);
        expect(screen.queryByRole('button', { name: 'Baca selengkapnya' })).not.toBeInTheDocument();
    });

    it('toggles open/closed for a long quote (> 150 chars)', () => {
        render(<ExpandableQuote text={'a'.repeat(200)} />);
        const toggle = screen.getByRole('button', { name: 'Baca selengkapnya' });
        fireEvent.click(toggle);
        expect(screen.getByRole('button', { name: 'Tutup' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Tutup' }));
        expect(screen.getByRole('button', { name: 'Baca selengkapnya' })).toBeInTheDocument();
    });

    it('clamps the quote to three lines while collapsed', () => {
        render(<ExpandableQuote text={'a'.repeat(200)} />);
        const paragraph = screen.getByText(new RegExp('a'.repeat(20)));
        expect(paragraph.className).toContain('line-clamp-3');
    });

    it('uses cream text on the sky panel', () => {
        render(<ExpandableQuote text="Lari santai aja." onSky />);
        const paragraph = screen.getByText(/Lari santai aja\./);
        expect(paragraph.className).toContain('text-cream');
        expect(paragraph.className).not.toContain('text-ink');
    });
});
