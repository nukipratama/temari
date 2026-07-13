import { render, screen } from '@testing-library/react';
import { createElement } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ProgressionChart from './ProgressionChart';

// Capture the props handed to the (lazy) Line chart so we can assert on the
// derived `data`/`options` and invoke the inline tooltip/tick callbacks, which
// Chart.js would normally call at render time against a real canvas.
type ChartData = {
    labels: string[];
    datasets: Array<{
        label: string;
        data: Array<number | null | { x: number; y: number }>;
        backgroundColor?: string | ((ctx: unknown) => string);
    }>;
};

type ChartOptions = {
    plugins: {
        tooltip: {
            callbacks: {
                label: (ctx: { dataset: { label?: string }; parsed: { y: number | null } }) => string;
            };
        };
    };
    scales: {
        y: { ticks: { callback: (val: number | string) => string } };
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

describe('ProgressionChart', () => {
    beforeEach(() => {
        lastData = null;
        lastOptions = null;
    });

    it('renders the empty state when there are no weeks', () => {
        render(<ProgressionChart weeks={[]} timesSec={[]} goalSec={null} />);

        expect(screen.getByText(/Belum cukup lari di jarak ini/)).toBeInTheDocument();
        expect(screen.queryByTestId('line-chart')).not.toBeInTheDocument();
    });

    it('applies a custom className to the empty state', () => {
        const { container } = render(
            <ProgressionChart weeks={[]} timesSec={[]} goalSec={null} className="custom-empty" />,
        );

        expect(container.querySelector('.custom-empty')).not.toBeNull();
    });

    it('renders the chart and builds the best-time dataset from times in minutes', async () => {
        render(
            <ProgressionChart
                weeks={['2026-01-05', '2026-01-12', '2026-01-19']}
                timesSec={[1500, null, 1440]}
                goalSec={null}
            />,
        );

        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
        expect(lastData).not.toBeNull();
        // Only the best-time dataset, no goal line.
        expect(lastData!.datasets).toHaveLength(1);
        expect(lastData!.datasets[0].label).toBe('Best time');
        // Seconds → minutes as {x,y} points spaced by real date (day offset from the
        // first week); a null time stays a null gap.
        expect(lastData!.datasets[0].data).toEqual([{ x: 0, y: 25 }, null, { x: 14, y: 24 }]);
        expect(lastData!.labels).toHaveLength(3);
    });

    it('adds a flat goal dataset when goalSec is provided', () => {
        render(
            <ProgressionChart
                weeks={['2026-01-05', '2026-01-12']}
                timesSec={[1500, 1440]}
                goalSec={1200}
            />,
        );

        expect(lastData!.datasets).toHaveLength(2);
        const goal = lastData!.datasets[1];
        expect(goal.label).toBe('Goal');
        // Flat line at goalSec/60 spanning the time range (first + last week x).
        expect(goal.data).toEqual([{ x: 0, y: 20 }, { x: 7, y: 20 }]);
    });

    it('omits the goal dataset when goalSec is zero (falsy)', () => {
        render(<ProgressionChart weeks={['2026-01-05']} timesSec={[1500]} goalSec={0} />);

        expect(lastData!.datasets).toHaveLength(1);
    });

    it('exposes an accessible name and data summary via role="img"', () => {
        render(
            <ProgressionChart
                weeks={['2026-01-05', '2026-01-12', '2026-01-19']}
                timesSec={[1500, null, 1440]}
                goalSec={null}
                category="5K"
            />,
        );

        const chart = screen.getByRole('img', { name: /Grafik progresi waktu terbaik 5K/ });
        expect(chart).toBeInTheDocument();
        expect(screen.getByText(/Dari 25:00 pada/)).toBeInTheDocument();
    });

    it('falls back to a generic accessible name when no category is given', () => {
        render(<ProgressionChart weeks={['2026-01-05']} timesSec={[1500]} goalSec={null} />);
        expect(screen.getByRole('img', { name: /^Grafik progresi waktu terbaik\./ })).toBeInTheDocument();
    });

    it('applies a custom className to the chart wrapper', () => {
        const { container } = render(
            <ProgressionChart
                weeks={['2026-01-05']}
                timesSec={[1500]}
                goalSec={null}
                className="custom-chart"
            />,
        );

        expect(container.querySelector('.custom-chart')).not.toBeNull();
    });

    describe('tooltip label callback', () => {
        const renderChart = () => {
            render(<ProgressionChart weeks={['2026-01-05']} timesSec={[1500]} goalSec={1200} />);
            return lastOptions!.plugins.tooltip.callbacks.label;
        };

        it('formats a present value as "<label>: H:MM:SS"', () => {
            const label = renderChart();
            // 25 minutes -> 25 * 60 = 1500s -> "25:00"
            expect(label({ dataset: { label: 'Best time' }, parsed: { y: 25 } })).toBe('Best time: 25:00');
        });

        it('returns an empty string for a null value (gap point)', () => {
            const label = renderChart();
            expect(label({ dataset: { label: 'Best time' }, parsed: { y: null } })).toBe('');
        });
    });

    describe('best-time area gradient', () => {
        it('falls back to a flat fill color when the chart area is not yet laid out', () => {
            render(<ProgressionChart weeks={['2026-01-05']} timesSec={[1500]} goalSec={null} />);
            const backgroundColor = lastData!.datasets[0].backgroundColor as (ctx: unknown) => string;

            const color = backgroundColor({ chart: { chartArea: undefined, ctx: {} } });

            // horizon token (#e8a076) at 0.18 alpha.
            expect(color).toBe('#e8a0762e');
        });

        it('builds a vertical gradient once the chart area is known', () => {
            render(<ProgressionChart weeks={['2026-01-05']} timesSec={[1500]} goalSec={null} />);
            const backgroundColor = lastData!.datasets[0].backgroundColor as (ctx: unknown) => string;
            const addColorStop = vi.fn();
            const createLinearGradient = vi.fn(() => ({ addColorStop }));

            const gradient = backgroundColor({
                chart: { chartArea: { top: 0, bottom: 260 }, ctx: { createLinearGradient } },
            });

            expect(createLinearGradient).toHaveBeenCalledWith(0, 0, 0, 260);
            expect(addColorStop).toHaveBeenCalledTimes(2);
            expect(gradient).toEqual({ addColorStop });
        });
    });

    describe('y-axis tick callback', () => {
        const renderChart = () => {
            render(<ProgressionChart weeks={['2026-01-05']} timesSec={[1500]} goalSec={null} />);
            return lastOptions!.scales.y.ticks.callback;
        };

        it('formats a numeric tick value as a duration', () => {
            const callback = renderChart();
            // 24 minutes -> "24:00"
            expect(callback(24)).toBe('24:00');
        });

        it('coerces a numeric string tick value to a duration', () => {
            const callback = renderChart();
            expect(callback('24')).toBe('24:00');
        });

        it('falls back to the raw string for a non-finite value', () => {
            const callback = renderChart();
            expect(callback('not-a-number')).toBe('not-a-number');
        });
    });
});
