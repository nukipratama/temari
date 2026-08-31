import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { StreamSummaryPerKm } from '@/types/inertia';

import SplitsChart from './SplitsChart';

const rows: StreamSummaryPerKm[] = [
    { km: 1, pace: '5:10', avg_hr: 144, avg_cadence_spm: 172 },
    { km: 2, pace: '4:40', avg_hr: 152, avg_cadence_spm: 175 },
    { km: 3, pace: '4:55', avg_hr: 158, avg_cadence_spm: 176 },
];

describe('SplitsChart', () => {
    it('draws one bar per km and explains how to read them', () => {
        render(<SplitsChart rows={rows} />);
        expect(screen.getByText('Splits per km')).toBeInTheDocument();
        expect(screen.getByText(/Taller bar, faster km/)).toBeInTheDocument();
        expect(screen.getAllByRole('button')).toHaveLength(3);
    });

    it('calls out the fastest km with its pace and heart rate', () => {
        render(<SplitsChart rows={rows} />);
        expect(screen.getByText('Km 2 · fastest · 152 bpm')).toBeInTheDocument();
        expect(screen.getByText('4:40/km')).toBeInTheDocument();
    });

    it('reveals a split’s numbers on tap and dims the other bars', () => {
        render(<SplitsChart rows={rows} />);
        fireEvent.click(screen.getByRole('button', { name: 'Km 1, 5:10 pace' }));

        expect(screen.getByRole('status')).toHaveTextContent('5:10/km');
        expect(screen.getByRole('status')).toHaveTextContent('♡ 144 · 172 spm');
        expect(
            document.querySelectorAll('.opacity-40').length,
        ).toBeGreaterThan(0);
    });

    it('clears the tooltip on a second tap of the same bar', () => {
        render(<SplitsChart rows={rows} />);
        const bar = screen.getByRole('button', { name: 'Km 1, 5:10 pace' });
        fireEvent.click(bar);
        fireEvent.click(bar);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('moves the tooltip to another bar rather than closing it', () => {
        render(<SplitsChart rows={rows} />);
        fireEvent.click(screen.getByRole('button', { name: 'Km 1, 5:10 pace' }));
        fireEvent.click(screen.getByRole('button', { name: 'Km 3, 4:55 pace' }));
        expect(screen.getByRole('status')).toHaveTextContent('4:55/km');
    });

    it('dashes readings the split does not carry', () => {
        render(<SplitsChart rows={[{ km: 1, pace: '5:10' }]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Km 1, 5:10 pace' }));
        expect(screen.getByRole('status')).toHaveTextContent('♡ — · — spm');
    });

    it('appends the trailing partial as a dashed, unranked bar', () => {
        const { container } = render(
            <SplitsChart
                rows={rows}
                partial={{ distance_m: 400, pace: '4:31' }}
            />,
        );
        expect(screen.getAllByRole('button')).toHaveLength(4);
        expect(screen.getByText('0.4')).toBeInTheDocument();
        expect(container.querySelector('.border-dashed')).not.toBeNull();
        // The remainder never wins "fastest" even at a quicker normalized pace.
        expect(screen.getByText('Km 2 · fastest · 152 bpm')).toBeInTheDocument();
    });

    it('traces heart rate over the bars when at least two kms recorded it', () => {
        const { container } = render(<SplitsChart rows={rows} />);
        expect(container.querySelector('polyline')).not.toBeNull();
    });

    it('draws no heart-rate trace for a run without one', () => {
        const { container } = render(
            <SplitsChart rows={[{ km: 1, pace: '5:10' }, { km: 2, pace: '5:00' }]} />,
        );
        expect(container.querySelector('polyline')).toBeNull();
    });

    it('renders a partial-only run with no fastest-km callout', () => {
        render(
            <SplitsChart rows={[]} partial={{ distance_m: 800, pace: '5:00' }} />,
        );
        expect(screen.getByText('0.8')).toBeInTheDocument();
        expect(screen.queryByText(/fastest/)).not.toBeInTheDocument();
    });
});
