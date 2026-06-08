import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ExpandableQuote from './ExpandableQuote';

describe('ExpandableQuote', () => {
    it('renders the quoted text', () => {
        render(<ExpandableQuote text="Lari santai aja." />);
        expect(screen.getByText(/Lari santai aja\./)).toBeInTheDocument();
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
});
