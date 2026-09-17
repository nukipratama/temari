import { render, screen } from '@testing-library/react';
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

    it('draws the full 365-day series as one fitness line, never sliced by a range', async () => {
        render(<FitnessPanel trend={pointsOverDays(365)} />);

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        expect(lastData!.datasets).toHaveLength(1);
        expect(lastData!.datasets[0].label).toBe('fitness');
        expect(lastData!.datasets[0].data).toHaveLength(365);
    });

    it('names the series and the date in the tooltip, not a raw value', async () => {
        render(<FitnessPanel trend={pointsOverDays(30)} />);

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
