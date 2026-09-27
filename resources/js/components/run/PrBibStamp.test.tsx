import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import PrBibStamp, { type PrBib } from './PrBibStamp';

afterEach(() => {
    vi.restoreAllMocks();
});

describe('PrBibStamp', () => {
    it('renders nothing when there is no bib to show', () => {
        const { container } = render(<PrBibStamp bib={null} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('formats a time-based record as label · PR · time', () => {
        const bib: PrBib = { label: '10K', value_sec: 3521, distance_m: null };
        render(<PrBibStamp bib={bib} />);
        expect(screen.getByText('10K · PR · 58:41')).toBeInTheDocument();
    });

    it('formats a distance-based record (longest run) in km', () => {
        const bib: PrBib = {
            label: 'Longest Run',
            value_sec: null,
            distance_m: 21_100,
        };
        render(<PrBibStamp bib={bib} />);
        expect(
            screen.getByText('Longest Run · PR · 21.1 km'),
        ).toBeInTheDocument();
    });

    it('buzzes once via navigator.vibrate when supported', () => {
        const vibrate = vi.fn();
        vi.stubGlobal('navigator', { ...navigator, vibrate });

        render(
            <PrBibStamp
                bib={{ label: '5K', value_sec: 1200, distance_m: null }}
            />,
        );

        expect(vibrate).toHaveBeenCalledTimes(1);
    });

    it('never throws when navigator.vibrate is unsupported (e.g. iOS Safari)', () => {
        vi.stubGlobal('navigator', { ...navigator, vibrate: undefined });

        expect(() =>
            render(
                <PrBibStamp
                    bib={{ label: '5K', value_sec: 1200, distance_m: null }}
                />,
            ),
        ).not.toThrow();
    });
});
