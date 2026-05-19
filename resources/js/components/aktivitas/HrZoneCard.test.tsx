import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import HrZoneCard from './HrZoneCard';

describe('HrZoneCard', () => {
    it('renders all 5 zone labels in the legend', () => {
        render(<HrZoneCard zonePct={{ Z1: 10, Z2: 30, Z3: 40, Z4: 15, Z5: 5 }} />);
        ['Z1', 'Z2', 'Z3', 'Z4', 'Z5'].forEach((z) => {
            expect(screen.getAllByText(new RegExp(z)).length).toBeGreaterThan(0);
        });
    });

    it('highlights the dominant zone in the header', () => {
        render(<HrZoneCard zonePct={{ Z1: 5, Z2: 60, Z3: 25, Z4: 8, Z5: 2 }} />);
        // Header shows "dominan Z2 · 60%"
        expect(screen.getByText(/Z2 · 60%/)).toBeInTheDocument();
    });

    it('skips zero-width segments in the bar', () => {
        const { container } = render(
            <HrZoneCard zonePct={{ Z1: 50, Z2: 50, Z3: 0, Z4: 0, Z5: 0 }} />,
        );
        // Bar should have 2 colored segments only.
        const segments = container.querySelectorAll('.h-3.overflow-hidden > div');
        expect(segments.length).toBe(2);
    });

    it('handles missing zone keys (treated as 0)', () => {
        render(<HrZoneCard zonePct={{ Z2: 100 }} />);
        expect(screen.getByText(/Z2 · 100%/)).toBeInTheDocument();
    });
});
