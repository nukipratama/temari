import { render, screen, waitFor } from '@testing-library/react';
import { createElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

import LoadTrend, { type LoadTrendPoint } from './LoadTrend';

type ChartData = {
    labels: string[];
    datasets: Array<{ label: string; data: Array<number | null> }>;
};

let seenData: ChartData[] = [];

vi.mock('react-chartjs-2', () => ({
    Line: (props: { data: ChartData }) => {
        seenData.push(props.data);
        return createElement('div', { 'data-testid': 'line-chart' });
    },
}));

function pointsOverDays(days: number): LoadTrendPoint[] {
    return Array.from({ length: days }, (_, i) => ({
        date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
        weekly_trimp: 300 + i,
        monotony: 1.2 + i * 0.001,
        strain: 300 + i * 2,
    }));
}

describe('LoadTrend', () => {
    it('shows the not-enough-history empty state when the trend is empty', () => {
        render(<LoadTrend trend={[]} range="12mo" />);

        expect(
            screen.getByText(/Not enough training history yet/),
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
            seenData = [];
            render(<LoadTrend trend={pointsOverDays(365)} range={range} />);

            await waitFor(() => expect(seenData).toHaveLength(2));
            expect(seenData[0].datasets[0].data).toHaveLength(expectedLength);
            expect(seenData[1].datasets[0].data).toHaveLength(expectedLength);
        },
    );

    it('renders strain and monotony as two separate charts', async () => {
        seenData = [];
        render(<LoadTrend trend={pointsOverDays(30)} range="30d" />);

        await waitFor(() => expect(seenData).toHaveLength(2));
        expect(seenData.map((d) => d.datasets[0].label)).toEqual([
            'Strain',
            'Monotony',
        ]);
    });

    it("shows the latest scored day's strain and monotony as stat tiles", async () => {
        const trend: LoadTrendPoint[] = [
            {
                date: '2026-01-01',
                weekly_trimp: 400,
                monotony: 1.4,
                strain: 550,
            },
            {
                date: '2026-01-02',
                weekly_trimp: 320,
                monotony: 1.6,
                strain: 300,
            },
        ];
        render(<LoadTrend trend={trend} range="30d" />);

        await waitFor(() => {
            expect(screen.getByText('300')).toBeInTheDocument();
            expect(screen.getByText('1.60')).toBeInTheDocument();
            expect(screen.getByText('550')).toBeInTheDocument();
        });
    });

    it('shows dashes when no day in the window is HR-scored', () => {
        const trend: LoadTrendPoint[] = [
            {
                date: '2026-01-01',
                weekly_trimp: null,
                monotony: null,
                strain: null,
            },
        ];
        render(<LoadTrend trend={trend} range="30d" />);

        expect(screen.getAllByText('—').length).toBeGreaterThan(0);
        expect(screen.getAllByText('No HR on these runs').length).toBe(2);
    });
});
