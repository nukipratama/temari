import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import PrBibStamp, { type PrBib } from './PrBibStamp';

afterEach(() => {
    vi.restoreAllMocks();
});

function bib(overrides: Partial<PrBib> = {}): PrBib {
    return {
        label: '10K',
        value_sec: 3521,
        distance_m: null,
        animate: false,
        ...overrides,
    };
}

describe('PrBibStamp', () => {
    it('renders nothing when there is no bib to show', () => {
        const { container } = render(<PrBibStamp bib={null} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('formats a time-based record as label · PR · time', () => {
        render(<PrBibStamp bib={bib()} />);
        expect(screen.getByText('10K · PR · 58:41')).toBeInTheDocument();
    });

    it('formats a distance-based record (longest run) in km', () => {
        render(
            <PrBibStamp
                bib={bib({
                    label: 'Longest Run',
                    value_sec: null,
                    distance_m: 21_100,
                })}
            />,
        );
        expect(
            screen.getByText('Longest Run · PR · 21.1 km'),
        ).toBeInTheDocument();
    });

    it('shows the badge on every view, whether or not it animates', () => {
        const { rerender } = render(
            <PrBibStamp bib={bib({ animate: true })} />,
        );
        expect(screen.getByText('10K · PR · 58:41')).toBeInTheDocument();

        rerender(<PrBibStamp bib={bib({ animate: false })} />);
        expect(screen.getByText('10K · PR · 58:41')).toBeInTheDocument();
    });

    it('applies the punch-in animation class only when animate is true', () => {
        const { container, rerender } = render(
            <PrBibStamp bib={bib({ animate: true })} />,
        );
        expect(container.firstChild).toHaveClass('pr-bib-stamp-animate');

        rerender(<PrBibStamp bib={bib({ animate: false })} />);
        expect(container.firstChild).not.toHaveClass('pr-bib-stamp-animate');
    });

    it('buzzes once via navigator.vibrate when animate is true', () => {
        const vibrate = vi.fn();
        vi.stubGlobal('navigator', { ...navigator, vibrate });

        render(<PrBibStamp bib={bib({ animate: true })} />);

        expect(vibrate).toHaveBeenCalledTimes(1);
    });

    it('never vibrates on a static (already-seen) view', () => {
        const vibrate = vi.fn();
        vi.stubGlobal('navigator', { ...navigator, vibrate });

        render(<PrBibStamp bib={bib({ animate: false })} />);

        expect(vibrate).not.toHaveBeenCalled();
    });

    it('never throws when navigator.vibrate is unsupported (e.g. iOS Safari)', () => {
        vi.stubGlobal('navigator', { ...navigator, vibrate: undefined });

        expect(() =>
            render(<PrBibStamp bib={bib({ animate: true })} />),
        ).not.toThrow();
    });
});
