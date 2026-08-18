import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createElement, useImperativeHandle, type Ref } from 'react';
import { describe, expect, it, vi } from 'vitest';

import FitnessTrend, {
    type BadgeMilestone,
    type FitnessTrendPoint,
} from './FitnessTrend';

type ChartData = {
    labels: string[];
    datasets: Array<{ label: string; data: number[] }>;
};

let lastData: ChartData | null = null;

vi.mock('react-chartjs-2', () => ({
    Line: (props: { data: ChartData; ref?: Ref<unknown> }) => {
        lastData = props.data;
        useImperativeHandle(props.ref, () => ({ update: () => {} }));
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
        render(<FitnessTrend trend={[]} milestones={[]} range="12mo" />);

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
            render(
                <FitnessTrend
                    trend={pointsOverDays(365)}
                    milestones={[]}
                    range={range}
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(lastData!.datasets[0].data).toHaveLength(expectedLength);
        },
    );

    it('labels the fitness and fatigue datasets', async () => {
        render(
            <FitnessTrend
                trend={pointsOverDays(30)}
                milestones={[]}
                range="30d"
            />,
        );

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
        render(<FitnessTrend trend={trend} milestones={[]} range="30d" />);

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
        render(<FitnessTrend trend={trend} milestones={[]} range="30d" />);

        expect(screen.getByText('Carrying load')).toBeInTheDocument();
    });

    describe('badge milestones', () => {
        const trend: FitnessTrendPoint[] = [
            { date: '2026-01-01', ctl: 40, atl: 30 },
            { date: '2026-01-02', ctl: 41, atl: 31 },
        ];

        it('shows the no-badges message when no milestone falls in the window', () => {
            render(<FitnessTrend trend={trend} milestones={[]} range="30d" />);

            expect(screen.getByText('0 badges')).toBeInTheDocument();
            expect(
                screen.getByText(/No badges landed in this window/),
            ).toBeInTheDocument();
        });

        it('only counts milestones whose date is inside the windowed trend', () => {
            const milestones: BadgeMilestone[] = [
                { key: 'early_bird', date: '2026-01-01' },
                { key: 'speedster', date: '2099-01-01' }, // not in trend at all
            ];
            render(
                <FitnessTrend
                    trend={trend}
                    milestones={milestones}
                    range="30d"
                />,
            );

            expect(screen.getByText('1 badges')).toBeInTheDocument();
            expect(screen.getByText('Early Bird')).toBeInTheDocument();
            expect(screen.queryByText('Speedster')).not.toBeInTheDocument();
        });

        it('selecting a chip shows its ability text, deselecting hides it', () => {
            const milestones: BadgeMilestone[] = [
                { key: 'early_bird', date: '2026-01-01' },
            ];
            render(
                <FitnessTrend
                    trend={trend}
                    milestones={milestones}
                    range="30d"
                />,
            );

            expect(
                screen.getByText(/Pick a badge to mark it on the line/),
            ).toBeInTheDocument();

            fireEvent.click(screen.getByRole('button', { name: /Early Bird/ }));

            expect(
                screen.getByText('Out the door before 6am.'),
            ).toBeInTheDocument();
            expect(
                screen.queryByText(/Pick a badge to mark it on the line/),
            ).not.toBeInTheDocument();

            fireEvent.click(screen.getByRole('button', { name: /Early Bird/ }));

            expect(
                screen.getByText(/Pick a badge to mark it on the line/),
            ).toBeInTheDocument();
        });
    });
});
