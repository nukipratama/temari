import { render, screen } from '@testing-library/react';
import { createElement } from 'react';
import { describe, expect, it, vi } from 'vitest';

import SupportedOverTime, {
    changeLine,
    stepLabels,
    tickStepSec,
    type SupportedHistory,
    type SupportedHistoryPoint,
} from './SupportedOverTime';

type ChartData = {
    datasets: Array<{
        label: string;
        data: number[];
        stepped?: boolean;
        borderDash?: number[];
    }>;
};
type ChartOptionsLike = {
    scales?: { y?: { reverse?: boolean } };
    plugins?: {
        supportedLabels?: {
            steps: Array<{ index: number; text: string }>;
            targetText: string;
        };
    };
};

let lastData: ChartData | null = null;
let lastOptions: ChartOptionsLike | null = null;

vi.mock('react-chartjs-2', () => ({
    Line: (props: { data: ChartData; options?: ChartOptionsLike }) => {
        lastData = props.data;
        lastOptions = props.options ?? null;
        return createElement('div', { 'data-testid': 'line-chart' });
    },
}));

function point(
    date: string,
    supported: number,
    overrides: Partial<SupportedHistoryPoint> = {},
): SupportedHistoryPoint {
    return {
        date,
        supported_time_sec: supported,
        source: { distance_m: 5000, date: '2026-07-20' },
        new_source: false,
        ...overrides,
    };
}

function history(points: SupportedHistoryPoint[]): SupportedHistory {
    return { target_time_sec: 3000, points };
}

describe('changeLine', () => {
    it('reads a faster supported time as a gain since the first point', () => {
        expect(
            changeLine([point('2026-08-03', 3300), point('2026-10-04', 3105)]),
        ).toEqual({ text: '3:15 faster since aug 3', tone: 'faster' });
    });

    it('reads a slower supported time as a loss since the first point', () => {
        expect(
            changeLine([point('2026-08-03', 3300), point('2026-10-04', 3340)]),
        ).toEqual({ text: '0:40 slower since aug 3', tone: 'slower' });
    });

    it('reads an unchanged supported time as no change', () => {
        expect(
            changeLine([point('2026-08-03', 3300), point('2026-10-04', 3300)]),
        ).toEqual({ text: 'no change since aug 3', tone: 'same' });
    });
});

describe('stepLabels', () => {
    it('labels only the points whose source effort changed', () => {
        expect(
            stepLabels([
                point('2026-08-03', 3300),
                point('2026-08-04', 3290),
                point('2026-10-01', 3200, {
                    source: { distance_m: 10_000, date: '2026-10-01' },
                    new_source: true,
                }),
                point('2026-10-02', 3200, {
                    source: { distance_m: 10_000, date: '2026-10-01' },
                }),
            ]),
        ).toEqual([{ index: 2, text: '10K · oct 1' }]);
    });
});

describe('tickStepSec', () => {
    it('picks a whole-minute step giving at most four ticks', () => {
        expect(tickStepSec([3480, 4044, 3375])).toBe(300);
        expect(tickStepSec([1500, 1560])).toBe(30);
        expect(tickStepSec([10_000, 30_000])).toBe(3600);
    });
});

describe('SupportedOverTime', () => {
    it('heads the panel with the current supported time and its change, in leaf-ink when faster', async () => {
        render(
            <SupportedOverTime
                history={history([
                    point('2026-08-03', 3300),
                    point('2026-10-04', 3105),
                ])}
            />,
        );

        expect(screen.getByText('supported over time')).toBeInTheDocument();
        expect(screen.getByText('51:45')).toBeInTheDocument();
        expect(screen.getByText('3:15 faster since aug 3')).toHaveClass(
            'text-leaf-ink',
        );
        expect(await screen.findByTestId('line-chart')).toBeInTheDocument();
    });

    it('marks a slower supported time in ember-ink', () => {
        render(
            <SupportedOverTime
                history={history([
                    point('2026-08-03', 3300),
                    point('2026-10-04', 3340),
                ])}
            />,
        );

        expect(screen.getByText('0:40 slower since aug 3')).toHaveClass(
            'text-ember-ink',
        );
    });

    it('draws a stepped supported line with faster up, a dashed target, and the step labels', async () => {
        render(
            <SupportedOverTime
                history={history([
                    point('2026-08-03', 3300),
                    point('2026-10-01', 3200, {
                        source: { distance_m: 10_000, date: '2026-10-01' },
                        new_source: true,
                    }),
                ])}
            />,
        );

        await screen.findByTestId('line-chart');
        expect(lastData!.datasets[0]).toMatchObject({
            label: 'supported',
            data: [3300, 3200],
            stepped: true,
        });
        expect(lastData!.datasets[1]).toMatchObject({
            label: 'your target',
            data: [3000, 3000],
            borderDash: [4, 4],
        });
        expect(lastOptions!.scales!.y!.reverse).toBe(true);
        expect(lastOptions!.plugins!.supportedLabels).toMatchObject({
            steps: [{ index: 1, text: '10K · oct 1' }],
            targetText: 'your target 50:00',
        });
    });

    it('is absent without a history', () => {
        const { container } = render(<SupportedOverTime history={null} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('is absent with fewer than two days of history', () => {
        const { container } = render(
            <SupportedOverTime
                history={history([point('2026-10-04', 3105)])}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });
});
