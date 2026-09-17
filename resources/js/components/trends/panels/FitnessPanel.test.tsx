import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createElement, useImperativeHandle, type Ref } from 'react';
import { describe, expect, it, vi } from 'vitest';

import FitnessPanel, {
    fitnessVerdict,
    type BadgeMilestone,
    type FitnessChartAnnotations,
    type FitnessTrendPoint,
    type StreakSummaryLike,
} from './FitnessPanel';

type ChartData = {
    labels: string[];
    datasets: Array<{ label: string; data: number[] }>;
};

// Minimal shape of what FitnessPanel's options/plugins actually build —
// loose enough to avoid depending on chart.js's own types in the test.
type ChartOptionsLike = {
    scales?: { x?: { display?: boolean; ticks?: { maxTicksLimit?: number } } };
    plugins?: {
        tooltip?: {
            callbacks?: {
                title?: (items: Array<{ dataIndex: number }>) => string;
                label?: (item: {
                    dataset: { label?: string };
                    parsed: { y: number };
                }) => string;
            };
        };
    };
};
type ChartPluginLike = {
    id: string;
    afterDatasetsDraw?: (chart: unknown) => void;
};

let lastData: ChartData | null = null;
let lastOptions: ChartOptionsLike | null = null;
let lastPlugins: ChartPluginLike[] | null = null;

vi.mock('react-chartjs-2', () => ({
    Line: (props: {
        data: ChartData;
        options?: ChartOptionsLike;
        plugins?: ChartPluginLike[];
        ref?: Ref<unknown>;
    }) => {
        lastData = props.data;
        lastOptions = props.options ?? null;
        lastPlugins = props.plugins ?? null;
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
        [40, 50, 45, 'climbing, not spiking.'],
        [40, 50, 60, 'climbing, and carrying the load.'],
        [50, 40, 35, 'easing off.'],
        [40, 40, 38, 'holding steady.'],
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
            screen.getByText(/not enough training history yet/),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('line-chart')).not.toBeInTheDocument();
    });

    it.each([
        ['7d', 7],
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

    it('draws fatigue dashed behind the solid fitness line, so fitness stays on top', async () => {
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
        expect(datasets.map((d) => d.label)).toEqual(['fatigue', 'fitness']);
        expect(datasets[0].borderDash).toEqual([3, 3]);
        expect(datasets[1].borderDash).toBeUndefined();
        expect(datasets.every((d) => d.fill === false)).toBe(true);
    });

    describe('chart legibility', () => {
        const trend = pointsOverDays(30);

        it('shows a labelled, decluttered x-axis of real dates', async () => {
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={[]}
                    streak={NO_STREAK}
                    range="30d"
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(lastData!.labels).toHaveLength(30);
            expect(lastData!.labels[0]).not.toBe('0');
            expect(lastOptions!.scales?.x?.display).not.toBe(false);
            expect(
                lastOptions!.scales?.x?.ticks?.maxTicksLimit,
            ).toBeGreaterThan(0);
        });

        it('names the series and the date in the tooltip, not a raw value', async () => {
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={[]}
                    streak={NO_STREAK}
                    range="30d"
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            const callbacks = lastOptions!.plugins!.tooltip!.callbacks!;
            expect(callbacks.title!([{ dataIndex: 0 }])).toBe('jan 1');
            expect(
                callbacks.label!({
                    dataset: { label: 'fitness' },
                    parsed: { y: 41.234 },
                }),
            ).toBe('fitness: 41');
        });

        it('draws no markers and no legend chip when nothing is annotated', async () => {
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={[]}
                    streak={NO_STREAK}
                    range="30d"
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(screen.queryByText('Deload week')).not.toBeInTheDocument();
            expect(screen.queryByText('Race day')).not.toBeInTheDocument();
        });

        it('marks a deload week and a race day from the plan, and labels each in the legend', async () => {
            const annotations: FitnessChartAnnotations = {
                deload: ['2026-01-05'],
                race: ['2026-01-20'],
            };
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={[]}
                    streak={NO_STREAK}
                    range="30d"
                    annotations={annotations}
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(screen.getByText('Deload week')).toBeInTheDocument();
            expect(screen.getByText('Race day')).toBeInTheDocument();
            expect(lastPlugins!.some((p) => p.id === 'trendMarkers')).toBe(
                true,
            );
        });

        it("mentions a race and a deload week in the chart's accessible summary", async () => {
            const annotations: FitnessChartAnnotations = {
                deload: ['2026-01-05'],
                race: ['2026-01-20'],
            };
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={[]}
                    streak={NO_STREAK}
                    range="30d"
                    annotations={annotations}
                />,
            );

            expect(
                screen.getByRole('img', {
                    name: /Marked on the chart: a deload week and a race\./,
                }),
            ).toBeInTheDocument();
        });

        it('ignores an annotation date outside the selected window', async () => {
            const annotations: FitnessChartAnnotations = {
                deload: ['2099-01-01'],
                race: [],
            };
            render(
                <FitnessPanel
                    trend={trend}
                    milestones={[]}
                    streak={NO_STREAK}
                    range="30d"
                    annotations={annotations}
                />,
            );

            expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
            expect(screen.queryByText('Deload week')).not.toBeInTheDocument();
        });
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
