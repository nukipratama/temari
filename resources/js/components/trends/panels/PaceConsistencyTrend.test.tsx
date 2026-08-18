import { render, screen, waitFor } from '@testing-library/react';
import { createElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

import PaceConsistencyTrend, {
    type PaceConsistencyPoint,
} from './PaceConsistencyTrend';

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

function pointsOverDays(days: number): PaceConsistencyPoint[] {
    return Array.from({ length: days }, (_, i) => ({
        date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
        variabilitySec: 10 + (i % 5),
    }));
}

describe('PaceConsistencyTrend', () => {
    it('shows the not-enough-history empty state when the trend is empty', () => {
        render(<PaceConsistencyTrend trend={[]} range="12mo" />);

        expect(
            screen.getByText(/Not enough pace history yet/),
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
                <PaceConsistencyTrend
                    trend={pointsOverDays(365)}
                    range={range}
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(lastData!.datasets[0].data).toHaveLength(expectedLength);
        },
    );

    it("labels the latest day's band", async () => {
        const trend: PaceConsistencyPoint[] = [
            { date: '2026-01-01', variabilitySec: 20 },
            { date: '2026-01-02', variabilitySec: 5 },
        ];
        render(<PaceConsistencyTrend trend={trend} range="30d" />);

        await waitFor(() => {
            expect(screen.getByText('Very steady')).toBeInTheDocument();
            expect(screen.getByText('5.0s')).toBeInTheDocument();
        });
    });

    it('flags a wide split spread as up and down', async () => {
        const trend: PaceConsistencyPoint[] = [
            { date: '2026-01-01', variabilitySec: 25 },
        ];
        render(<PaceConsistencyTrend trend={trend} range="30d" />);

        await waitFor(() => {
            expect(screen.getByText('Up and down')).toBeInTheDocument();
        });
    });
});
