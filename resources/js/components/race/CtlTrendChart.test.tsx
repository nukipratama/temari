import { render, screen } from '@testing-library/react';
import { createElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { formatNaiveIdDate } from '@/lib/pace';

import CtlTrendChart from './CtlTrendChart';

type ChartData = {
    labels: string[];
    datasets: Array<{
        label: string;
        data: number[];
        borderDash?: number[];
    }>;
};

type ChartOptions = {
    plugins: {
        tooltip: {
            callbacks: {
                title: (items: Array<{ dataIndex: number }>) => string;
            };
        };
    };
};

let lastData: ChartData | null = null;
let lastOptions: ChartOptions | null = null;

vi.mock('react-chartjs-2', () => ({
    Line: (props: { data: ChartData; options: ChartOptions }) => {
        lastData = props.data;
        lastOptions = props.options;
        return createElement('div', { 'data-testid': 'line-chart' });
    },
}));

const TREND = [
    { date: '2026-05-01', atl: 40, ctl: 50 },
    { date: '2026-05-02', atl: 42, ctl: 51 },
    { date: '2026-05-03', atl: 45, ctl: 52 },
];

describe('CtlTrendChart', () => {
    beforeEach(() => {
        lastData = null;
        lastOptions = null;
    });

    it('renders the empty state when there is no trend', () => {
        render(<CtlTrendChart trend={[]} />);

        expect(
            screen.getByText(/Not enough training history/),
        ).toBeInTheDocument();
        expect(screen.queryByTestId('line-chart')).not.toBeInTheDocument();
    });

    it('builds a CTL and an ATL dataset from the trend', async () => {
        render(<CtlTrendChart trend={TREND} />);

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        expect(lastData!.datasets).toHaveLength(2);
        expect(lastData!.datasets[0].label).toBe('Fitness (CTL)');
        expect(lastData!.datasets[0].data).toEqual([50, 51, 52]);
        expect(lastData!.datasets[1].label).toBe('Fatigue (ATL)');
        expect(lastData!.datasets[1].data).toEqual([40, 42, 45]);
        // ATL is the dashed line, CTL is solid.
        expect(lastData!.datasets[1].borderDash).toEqual([4, 4]);
        expect(lastData!.datasets[0].borderDash).toBeUndefined();
    });

    it('exposes an accessible name summarizing the fitness range', () => {
        render(<CtlTrendChart trend={TREND} />);

        expect(
            screen.getByRole('img', {
                name: /Fitness 50 to 52 over 3 days/,
            }),
        ).toBeInTheDocument();
    });

    it('surfaces the point date via the tooltip title callback', () => {
        render(<CtlTrendChart trend={TREND} />);

        const title = lastOptions!.plugins.tooltip.callbacks.title;
        expect(title([{ dataIndex: 1 }])).toBe(
            formatNaiveIdDate('2026-05-02', 'short'),
        );
    });

    it('applies a custom className to the empty state', () => {
        const { container } = render(
            <CtlTrendChart trend={[]} className="custom-empty" />,
        );
        expect(container.querySelector('.custom-empty')).not.toBeNull();
    });

    it('applies a custom className to the chart wrapper', () => {
        const { container } = render(
            <CtlTrendChart trend={TREND} className="custom-chart" />,
        );
        expect(container.querySelector('.custom-chart')).not.toBeNull();
    });
});
