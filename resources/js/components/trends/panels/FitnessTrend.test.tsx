import { render, screen, waitFor } from '@testing-library/react';
import { createElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

import FitnessTrend, { type FitnessTrendPoint } from './FitnessTrend';

type ChartData = {
    labels: string[];
    datasets: Array<{ label: string; data: number[] }>;
};

let lastData: ChartData | null = null;

vi.mock('react-chartjs-2', () => ({
    Line: (props: { data: ChartData }) => {
        lastData = props.data;
        return createElement('div', { 'data-testid': 'line-chart' });
    },
}));

function pointsOverDays(days: number): FitnessTrendPoint[] {
    return Array.from({ length: days }, (_, i) => ({
        date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
        ctl: 40 + i * 0.1,
        atl: 35 + i * 0.05,
    }));
}

describe('FitnessTrend', () => {
    it('shows the not-enough-history empty state when the trend is empty', () => {
        render(<FitnessTrend trend={[]} range="12mo" />);

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
            render(<FitnessTrend trend={pointsOverDays(365)} range={range} />);

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(lastData!.datasets[0].data).toHaveLength(expectedLength);
        },
    );

    it('labels the fitness and fatigue datasets', async () => {
        render(<FitnessTrend trend={pointsOverDays(30)} range="30d" />);

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        expect(lastData!.datasets.map((d) => d.label)).toEqual([
            'Fitness',
            'Fatigue',
        ]);
    });

    it("shows the latest point's fitness, fatigue and form as stat tiles", async () => {
        const trend: FitnessTrendPoint[] = [
            { date: '2026-01-01', ctl: 40, atl: 30 },
            { date: '2026-01-02', ctl: 45, atl: 32 },
        ];
        render(<FitnessTrend trend={trend} range="30d" />);

        await waitFor(() => {
            expect(screen.getByText('45')).toBeInTheDocument();
            expect(screen.getByText('32')).toBeInTheDocument();
            expect(screen.getByText('+13')).toBeInTheDocument();
        });
    });

    it('shows a negative form value when fatigue outweighs fitness', () => {
        const trend: FitnessTrendPoint[] = [
            { date: '2026-01-01', ctl: 30, atl: 45 },
        ];
        render(<FitnessTrend trend={trend} range="30d" />);

        expect(screen.getByText('Carrying load')).toBeInTheDocument();
    });
});
