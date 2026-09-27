import { act, fireEvent, render, screen } from '@testing-library/react';
import { createElement, useImperativeHandle, type Ref } from 'react';
import { describe, expect, it, vi } from 'vitest';

import FitnessPanel, {
    type FitnessChartAnnotations,
    type FitnessTrendPoint,
} from './FitnessPanel';

type ChartData = {
    labels: string[];
    datasets: Array<{ label: string; data: number[] }>;
};

type ChartOptionsLike = {
    scales?: { x?: { display?: boolean; ticks?: { maxTicksLimit?: number } } };
    plugins?: { tooltip?: { enabled?: boolean } };
    onHover?: (event: unknown, elements: Array<{ index: number }>) => void;
};
type ChartPluginLike = { id: string };

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

function pointsOverDays(
    days: number,
    { ctlStart = 40, dip = false }: { ctlStart?: number; dip?: boolean } = {},
): FitnessTrendPoint[] {
    return Array.from({ length: days }, (_, i) => {
        const ctl = ctlStart + i * 0.1;
        // form_status is now a server-stamped field — "fresh" by default,
        // with a brief "overreaching" spike partway through when dip is set,
        // so both ends of the band actually appear.
        const overreaching = dip && i > days / 2 && i < days / 2 + 5;
        return {
            date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
            ctl,
            atl: ctl - 25,
            form_status: overreaching ? 'overreaching' : 'fresh',
        };
    });
}

describe('FitnessPanel', () => {
    it('shows the not-enough-history empty state when the trend is empty', () => {
        render(<FitnessPanel trend={[]} />);

        expect(
            screen.getByText(/not enough training history yet/),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('line-chart')).not.toBeInTheDocument();
    });

    it('draws the trailing 3M (90 days) by default out of a longer history', async () => {
        render(<FitnessPanel trend={pointsOverDays(365)} />);

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        expect(lastData!.datasets).toHaveLength(1);
        expect(lastData!.datasets[0].label).toBe('fitness');
        expect(lastData!.datasets[0].data).toHaveLength(90);
    });

    it('re-slices the chart when a range chip is picked', async () => {
        render(<FitnessPanel trend={pointsOverDays(365)} />);
        await screen.findByTestId('line-chart');

        fireEvent.click(screen.getByRole('button', { name: '1M' }));
        expect(lastData!.datasets[0].data).toHaveLength(30);

        fireEvent.click(screen.getByRole('button', { name: '1Y' }));
        expect(lastData!.datasets[0].data).toHaveLength(365);
    });

    it('disables the default tooltip box in favour of the scrub readout', async () => {
        render(<FitnessPanel trend={pointsOverDays(30)} />);

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        expect(lastOptions!.plugins!.tooltip!.enabled).toBe(false);
    });

    it('shows a headline with the value now and the change since the range start', async () => {
        render(<FitnessPanel trend={pointsOverDays(90)} />);

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        expect(screen.getByText('49')).toBeInTheDocument();
        expect(screen.getByText('+9')).toBeInTheDocument();
        expect(screen.getByText(/since jan/)).toBeInTheDocument();
    });

    it('swaps the headline for a per-day readout while scrubbing', async () => {
        render(<FitnessPanel trend={pointsOverDays(30)} />);
        await screen.findByTestId('line-chart');

        act(() => {
            lastOptions!.onHover!({}, [{ index: 5 }]);
        });

        expect(
            await screen.findByText(/jan 6 · fitness 41/),
        ).toBeInTheDocument();
    });

    it('draws the deload marker plugin when a deload date falls inside the trend', async () => {
        const annotations: FitnessChartAnnotations = {
            deload: ['2026-01-05'],
            race: ['2026-01-20'],
        };
        render(
            <FitnessPanel
                trend={pointsOverDays(30)}
                annotations={annotations}
            />,
        );

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        expect(lastPlugins!.some((p) => p.id === 'trendDeloadMarker')).toBe(
            true,
        );
    });

    it('draws a form-status band beneath the chart, collapsed into runs', async () => {
        render(<FitnessPanel trend={pointsOverDays(30, { dip: true })} />);

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        // Fresh (from the -5 gap) and tired (from the injected spike) both
        // surface in the legend.
        expect(screen.getByText('fresh')).toBeInTheDocument();
        expect(screen.getByText('tired')).toBeInTheDocument();
    });

    it('mentions the current fitness reading in the accessible summary', async () => {
        const trend = pointsOverDays(10);
        render(<FitnessPanel trend={trend} />);

        expect(
            screen.getByRole('img', {
                name: new RegExp(
                    `now at ${trend[trend.length - 1].ctl.toFixed(1)}`,
                ),
            }),
        ).toBeInTheDocument();
    });
});
