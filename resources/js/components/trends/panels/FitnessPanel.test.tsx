import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createElement, useImperativeHandle, type Ref } from 'react';
import { describe, expect, it, vi } from 'vitest';

import FitnessPanel, {
    fitnessVerdict,
    type BadgeMilestone,
    type FitnessTrendPoint,
    type StreakSummaryLike,
} from './FitnessPanel';

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

const NO_STREAK: StreakSummaryLike = {
    weeks: 0,
    rest_weeks_held: 0,
    rest_weeks_cap: 2,
    ran_this_week: false,
    week_ends_on: '2026-08-30',
};

function pointsOverDays(days: number): FitnessTrendPoint[] {
    return Array.from({ length: days }, (_, i) => ({
        date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
        ctl: 40 + i * 0.1,
        atl: 35 + i * 0.05,
    }));
}

describe('fitnessVerdict', () => {
    it.each([
        [40, 50, 45, 'Climbing, not spiking.'],
        [40, 50, 60, 'Climbing, and carrying the load.'],
        [50, 40, 35, 'Easing off.'],
        [40, 40, 38, 'Holding steady.'],
    ])('reads %s → %s (fatigue %s) as "%s"', (first, last, atl, expected) => {
        expect(fitnessVerdict(first, last, atl)).toBe(expected);
    });
});

describe('FitnessPanel', () => {
    it('shows the not-enough-history empty state when the trend is empty', () => {
        render(
            <FitnessPanel
                trend={[]}
                milestones={[]}
                streak={NO_STREAK}
                range="12mo"
            />,
        );

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
                <FitnessPanel
                    trend={pointsOverDays(365)}
                    milestones={[]}
                    streak={NO_STREAK}
                    range={range}
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(lastData!.datasets[0].data).toHaveLength(expectedLength);
        },
    );

    it('draws fitness solid and fatigue dashed, neither filled', async () => {
        render(
            <FitnessPanel
                trend={pointsOverDays(30)}
                milestones={[]}
                streak={NO_STREAK}
                range="30d"
            />,
        );

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        const datasets = lastData!.datasets as Array<{
            label: string;
            borderDash?: number[];
            fill?: boolean;
        }>;
        expect(datasets.map((d) => d.label)).toEqual(['Fitness', 'Fatigue']);
        expect(datasets[0].borderDash).toBeUndefined();
        expect(datasets[1].borderDash).toEqual([3, 3]);
        expect(datasets.every((d) => d.fill === false)).toBe(true);
    });

    it("shows the latest point's fitness, fatigue and form as stat tiles", async () => {
        const trend: FitnessTrendPoint[] = [
            { date: '2026-01-01', ctl: 40, atl: 30 },
            { date: '2026-01-02', ctl: 45, atl: 32 },
        ];
        render(
            <FitnessPanel
                trend={trend}
                milestones={[]}
                streak={NO_STREAK}
                range="30d"
            />,
        );

        await waitFor(() => {
            expect(screen.getByText('45')).toBeInTheDocument();
            expect(screen.getByText('32')).toBeInTheDocument();
            expect(screen.getByText('+13')).toBeInTheDocument();
        });
    });

    it('shows a negative form value when fatigue outweighs fitness', async () => {
        const trend: FitnessTrendPoint[] = [
            { date: '2026-01-01', ctl: 30, atl: 45 },
        ];
        render(
            <FitnessPanel
                trend={trend}
                milestones={[]}
                streak={NO_STREAK}
                range="30d"
            />,
        );

        await waitFor(() => {
            expect(screen.getByText('-15')).toBeInTheDocument();
        });
    });

    describe('badge chips', () => {
        const trend: FitnessTrendPoint[] = [
            { date: '2026-01-01', ctl: 40, atl: 30 },
            { date: '2026-01-02', ctl: 41, atl: 31 },
        ];

        it('draws no chip row when nothing is earned and no streak runs', () => {
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={[]}
                    streak={NO_STREAK}
                    range="30d"
                />,
            );

            expect(screen.queryByRole('list')).not.toBeInTheDocument();
        });

        it('only shows badges whose date is inside the windowed trend', () => {
            const milestones: BadgeMilestone[] = [
                { key: 'early_bird', date: '2026-01-01', rarity: 'rare' },
                { key: 'speedster', date: '2099-01-01', rarity: 'epic' },
            ];
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={milestones}
                    streak={NO_STREAK}
                    range="30d"
                />,
            );

            expect(screen.getByText('Early Bird')).toBeInTheDocument();
            expect(screen.queryByText('Speedster')).not.toBeInTheDocument();
        });

        it('wraps every earned badge rather than truncating the row', () => {
            const milestones: BadgeMilestone[] = [
                { key: 'early_bird', date: '2026-01-01', rarity: 'rare' },
                { key: 'speedster', date: '2026-01-01', rarity: 'epic' },
                { key: 'climber', date: '2026-01-02', rarity: 'common' },
                { key: 'headwind', date: '2026-01-02', rarity: 'uncommon' },
            ];
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={milestones}
                    streak={NO_STREAK}
                    range="30d"
                />,
            );

            expect(screen.getByRole('list')).toHaveClass('flex-wrap');
            expect(screen.getAllByRole('listitem')).toHaveLength(4);
        });

        it('selecting a chip shows its detail, deselecting hides it', () => {
            const milestones: BadgeMilestone[] = [
                { key: 'early_bird', date: '2026-01-01', rarity: 'rare' },
            ];
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={milestones}
                    streak={NO_STREAK}
                    range="30d"
                />,
            );

            expect(
                screen.queryByText(/Out the door before 6am\./),
            ).not.toBeInTheDocument();

            fireEvent.click(screen.getByRole('button', { name: /Early Bird/ }));

            expect(
                screen.getByText(/Out the door before 6am\./),
            ).toBeInTheDocument();

            fireEvent.click(screen.getByRole('button', { name: /Early Bird/ }));

            expect(
                screen.queryByText(/Out the door before 6am\./),
            ).not.toBeInTheDocument();
        });

        it('leads with the week-streak chip and explains it when tapped', () => {
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={[]}
                    streak={{ ...NO_STREAK, weeks: 6, rest_weeks_held: 1 }}
                    range="30d"
                />,
            );

            const chip = screen.getByRole('button', { name: /6-week streak/ });
            expect(chip).toBeInTheDocument();

            fireEvent.click(chip);

            expect(
                screen.getByText(/6 consecutive weeks with at least one run/),
            ).toBeInTheDocument();
            expect(screen.getByText(/1 rest week in hand/)).toBeInTheDocument();
        });
    });
});
