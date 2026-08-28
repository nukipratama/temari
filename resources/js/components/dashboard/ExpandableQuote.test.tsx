import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ExpandableQuote from './ExpandableQuote';

describe('ExpandableQuote', () => {
    it('renders the quoted text', () => {
        render(<ExpandableQuote text="Easy does it." />);
        expect(screen.getByText(/Easy does it\./)).toBeInTheDocument();
    });

    // Narration that opens by quoting a card name would otherwise collide with
    // the decorative frame and render as a doubled opening quote.
    it('strips an inner opening quote so the decorative frame is the only one', () => {
        render(
            <ExpandableQuote
                text={'"Full Send" once-in-a-lifetime progress, celebrate it.'}
            />,
        );
        const paragraph = screen.getByText(/Full Send/);

        expect(paragraph.textContent).toBe(
            '“Full Send once-in-a-lifetime progress, celebrate it.”',
        );
    });

    it('leaves a mid-string quote alone (e.g. a pace like 5\'30")', () => {
        render(<ExpandableQuote text={'Pace 5\'30" is clean.'} />);
        const paragraph = screen.getByText(/Pace/);

        expect(paragraph.textContent).toBe('“Pace 5\'30" is clean.”');
    });

    it('omits the toggle for a short quote (<= 150 chars)', () => {
        render(<ExpandableQuote text="short" />);
        expect(
            screen.queryByRole('button', { name: 'Read more' }),
        ).not.toBeInTheDocument();
    });

    it('toggles open/closed for a long quote (> 150 chars)', () => {
        render(<ExpandableQuote text={'a'.repeat(200)} />);
        const toggle = screen.getByRole('button', {
            name: 'Read more',
        });
        fireEvent.click(toggle);
        expect(
            screen.getByRole('button', { name: 'Show less' }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Show less' }));
        expect(
            screen.getByRole('button', { name: 'Read more' }),
        ).toBeInTheDocument();
    });

    it('clamps the quote to three lines while collapsed', () => {
        render(<ExpandableQuote text={'a'.repeat(200)} />);
        const paragraph = screen.getByText(new RegExp('a'.repeat(20)));
        expect(paragraph.className).toContain('line-clamp-3');
    });

    it('uses cream text on the sky panel', () => {
        render(<ExpandableQuote text="Easy does it." onSky />);
        const paragraph = screen.getByText(/Easy does it\./);
        expect(paragraph).toHaveClass('text-cream');
        expect(paragraph).not.toHaveClass('text-foreground');
    });

    it('hands its ground down to the toggle', () => {
        const { rerender } = render(<ExpandableQuote text={'a'.repeat(200)} />);
        expect(screen.getByRole('button')).toHaveClass('text-horizon-ink');

        rerender(<ExpandableQuote text={'a'.repeat(200)} onSky />);
        expect(screen.getByRole('button')).toHaveClass('text-horizon');
    });
});
