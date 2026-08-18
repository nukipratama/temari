import { render, screen, waitFor } from '@testing-library/react';
import { createElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

import VdotTrend, { type VdotHistoryPoint } from './VdotTrend';

type ChartData = {
    labels: string[];
    datasets: Array<{ label: string; data: Array<number | null> }>;
};

let lastData: ChartData | null = null;

vi.mock('react-chartjs-2', () => ({
    Line: (props: { data: ChartData }) => {
        lastData = props.data;
        return createElement('div', { 'data-testid': 'line-chart' });
    },
}));

function pointsOverDays(days: number): VdotHistoryPoint[] {
    return Array.from({ length: days }, (_, i) => ({
        date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
        vdot: 40 + i * 0.05,
    }));
}

describe('VdotTrend', () => {
    it('shows the not-enough-history empty state when the trend is empty', () => {
        render(<VdotTrend trend={[]} sourceCategory={null} range="12mo" />);

        expect(
            screen.getByText(/Not enough VDOT history yet/),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('line-chart')).not.toBeInTheDocument();
    });

    it.each([
        ['30d', 30],
        ['90d', 90],
        ['12mo', 365],
    ] as const)(
        'slices the full year of points down to the last %s window',
        async (range, expectedLength) => {
            render(
                <VdotTrend
                    trend={pointsOverDays(365)}
                    sourceCategory="10 km"
                    range={range}
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(lastData!.datasets[0].data).toHaveLength(expectedLength);
        },
    );

    it('shows the latest VDOT, its change, and the limiting category', async () => {
        const trend: VdotHistoryPoint[] = [
            { date: '2026-01-01', vdot: 40 },
            { date: '2026-01-02', vdot: 45 },
        ];
        render(<VdotTrend trend={trend} sourceCategory="10 km" range="30d" />);

        await waitFor(() => {
            expect(screen.getByText('45.0')).toBeInTheDocument();
            expect(screen.getByText('+5.0')).toBeInTheDocument();
            expect(screen.getByText('10 km')).toBeInTheDocument();
        });
    });

    it('shows a dash for the limiting category when the user has no eligible PR', () => {
        const trend: VdotHistoryPoint[] = [{ date: '2026-01-01', vdot: 40 }];
        render(<VdotTrend trend={trend} sourceCategory={null} range="30d" />);

        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    });
});
