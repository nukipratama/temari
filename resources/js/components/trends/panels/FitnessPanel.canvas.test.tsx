import 'vitest-canvas-mock';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { Chart } from 'chart.js';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import FitnessPanel, { type FitnessTrendPoint } from './FitnessPanel';

vi.unmock('react-chartjs-2');

beforeEach(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue(
        DOMRect.fromRect({ x: 0, y: 0, width: 600, height: 168 }),
    );
});

afterEach(() => {
    vi.restoreAllMocks();
    document.documentElement.removeAttribute('data-theme');
});

function trendOverDays(days: number): FitnessTrendPoint[] {
    const start = Date.UTC(2025, 0, 1);
    return Array.from({ length: days }, (_, i) => ({
        date: new Date(start + i * 86_400_000).toISOString().slice(0, 10),
        ctl: 40 + i * 0.1,
        atl: 30,
        form_status: 'fresh',
    }));
}

async function mountedChart(container: HTMLElement): Promise<Chart<'line'>> {
    const canvas = await waitFor(() => {
        const found = container.querySelector('canvas');
        expect(found).not.toBeNull();
        return found as HTMLCanvasElement;
    });
    const chart = Chart.getChart(canvas);
    expect(chart).toBeDefined();
    return chart as Chart<'line'>;
}

function drawAndRecord(chart: Chart<'line'>) {
    const ctx = chart.ctx as unknown as {
        fillRect: { mock: { calls: number[][] } };
        moveTo: { mock: { calls: number[][] } };
    };
    vi.mocked(chart.ctx.fillRect).mockClear();
    vi.mocked(chart.ctx.moveTo).mockClear();
    act(() => chart.draw());
    return {
        fillRectXs: ctx.fillRect.mock.calls.map((call) => call[0]),
        moveToXs: ctx.moveTo.mock.calls
            .filter((call) => call[1] === chart.chartArea.top)
            .map((call) => call[0]),
    };
}

describe('FitnessPanel on a real Chart.js canvas', () => {
    it('draws the highlight band, deload marks and cursor from the live range, cursor and ground', async () => {
        const trend = trendOverDays(365);
        const deloadDate = trend[trend.length - 10].date;
        const { container } = render(
            <FitnessPanel
                trend={trend}
                annotations={{ deload: [deloadDate], race: [] }}
                highlightDays={30}
            />,
        );
        const chart = await mountedChart(container);

        fireEvent.click(screen.getByRole('button', { name: '1Y' }));
        expect(chart.data.labels).toHaveLength(365);

        const x = chart.scales.x;
        const afterRange = drawAndRecord(chart);
        expect(afterRange.fillRectXs).toContain(x.getPixelForValue(335));
        expect(afterRange.moveToXs).toContain(x.getPixelForValue(355));

        await act(async () => {
            document.documentElement.setAttribute('data-theme', 'dark');
        });
        expect(chart.options.scales!.x!.ticks!.color).toBe('#c1c2c8');

        act(() => {
            chart.options.onHover?.call(
                chart,
                new MouseEvent('mousemove') as never,
                [{ index: 200 } as never],
                chart,
            );
        });
        expect(screen.getByText(/· long-term load/)).toBeInTheDocument();

        const strokeStyles: string[] = [];
        const stroke = vi
            .spyOn(chart.ctx, 'stroke')
            .mockImplementation(function (this: CanvasRenderingContext2D) {
                strokeStyles.push(String(this.strokeStyle));
            });
        const afterHover = drawAndRecord(chart);
        stroke.mockRestore();
        expect(afterHover.moveToXs).toContain(x.getPixelForValue(200));
        expect(strokeStyles).toContain('#4d5560');
    });

    it('draws no tween when the user prefers reduced motion', async () => {
        vi.spyOn(window, 'matchMedia').mockImplementation(
            (query: string) =>
                ({
                    matches: query === '(prefers-reduced-motion: reduce)',
                    media: query,
                    addEventListener: () => {},
                    removeEventListener: () => {},
                }) as unknown as MediaQueryList,
        );
        const { container } = render(
            <FitnessPanel trend={trendOverDays(90)} />,
        );
        const chart = await mountedChart(container);

        expect(chart.options.animation).toBe(false);
    });
});
