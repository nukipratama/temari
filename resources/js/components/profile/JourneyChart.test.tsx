import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import JourneyChart from './JourneyChart';

const WEEKS = ['2026-05-25', '2026-06-08', '2026-06-22'];
const TIMES = [3160, 3117, 2895];

function mockChartRect() {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
        width: 300,
        height: 78,
        left: 0,
        right: 300,
        top: 0,
        bottom: 78,
        x: 0,
        y: 0,
        toJSON: () => ({}),
    });
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('JourneyChart', () => {
    it('summarises the span for assistive tech', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);

        expect(
            screen.getByText(
                'Best time journey. from 52:40 on may 25 to 48:15 on jun 22.',
            ),
        ).toBeInTheDocument();
    });

    it('draws an empty state when no week carries a time', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={[null, null, null]} />);

        expect(
            screen.getByText(/not enough runs at this distance yet/),
        ).toBeInTheDocument();
    });

    it('centres a lone point rather than dividing by a zero span', () => {
        const { container } = render(
            <JourneyChart weeks={['2026-05-25']} timesSec={[3160]} />,
        );
        const dot = container.querySelector(
            'line[vector-effect="non-scaling-stroke"]',
        );

        expect(dot?.getAttribute('x1')).toBe('150');
        expect(dot?.getAttribute('y1')).toBe('39');
    });

    it('draws every marker as a zero-length round-capped line that stays round at any width', () => {
        const { container } = render(
            <JourneyChart weeks={WEEKS} timesSec={TIMES} />,
        );
        const markers = Array.from(
            container.querySelectorAll(
                'line[vector-effect="non-scaling-stroke"]',
            ),
        );

        expect(container.querySelector('circle, ellipse')).toBeNull();
        expect(markers).toHaveLength(4);
        markers.forEach((marker) => {
            expect(marker.getAttribute('x1')).toBe(marker.getAttribute('x2'));
            expect(marker.getAttribute('y1')).toBe(marker.getAttribute('y2'));
            expect(marker.getAttribute('stroke-linecap')).toBe('round');
        });
        expect(
            markers.map((marker) => [
                marker.getAttribute('stroke'),
                marker.getAttribute('stroke-width'),
            ]),
        ).toEqual([
            ['var(--color-horizon-ink)', '5'],
            ['var(--color-horizon-ink)', '5'],
            ['var(--color-card)', '12'],
            ['var(--color-horizon)', '8'],
        ]);
    });

    it('grows the selected marker to the PR size', () => {
        const { container } = render(
            <JourneyChart weeks={WEEKS} timesSec={TIMES} />,
        );

        fireEvent.keyDown(screen.getByRole('slider'), { key: 'ArrowLeft' });
        const markers = container.querySelectorAll(
            'line[vector-effect="non-scaling-stroke"]',
        );

        expect(markers[1].getAttribute('stroke-width')).toBe('10');
    });

    it('moves the readout between weeks with the arrow keys, most recent first', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);
        const chart = screen.getByRole('slider');

        fireEvent.keyDown(chart, { key: 'ArrowLeft' });
        expect(screen.getByText(/jun 8 · 51:57/)).toBeInTheDocument();

        fireEvent.keyDown(chart, { key: 'ArrowLeft' });
        expect(screen.getByText(/may 25 · 52:40/)).toBeInTheDocument();

        fireEvent.keyDown(chart, { key: 'ArrowRight' });
        expect(screen.getByText(/jun 8 · 51:57/)).toBeInTheDocument();
    });

    it('closes the readout with Escape', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);
        const chart = screen.getByRole('slider');

        fireEvent.keyDown(chart, { key: 'ArrowLeft' });
        expect(screen.getByText(/jun 8/)).toBeInTheDocument();

        fireEvent.keyDown(chart, { key: 'Escape' });
        expect(screen.queryByText(/jun 8/)).not.toBeInTheDocument();
    });

    it('closes the readout on a click outside the chart', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);

        fireEvent.keyDown(screen.getByRole('slider'), { key: 'ArrowLeft' });
        expect(screen.getByText(/jun 8/)).toBeInTheDocument();

        fireEvent.click(document.body);
        expect(screen.queryByText(/jun 8/)).not.toBeInTheDocument();
    });

    it('scrubs to the nearest week on a pointer drag and shows the PR readout with a run link', () => {
        mockChartRect();
        render(
            <JourneyChart
                weeks={WEEKS}
                timesSec={TIMES}
                activityIds={[101, 102, 103]}
            />,
        );
        const chart = screen.getByRole('slider');

        // x=292 sits nearest the last point (jun 22, the PR).
        fireEvent.pointerDown(chart, {
            clientX: 292,
            pointerId: 1,
            pointerType: 'touch',
        });

        expect(screen.getByText(/jun 22 · PR · 48:15/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /open run/ })).toHaveAttribute(
            'href',
            '/activities/103',
        );
    });

    it('scrubs on mouse hover alone, without a pointerdown', () => {
        mockChartRect();
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);
        const chart = screen.getByRole('slider');

        // x=150 sits nearest the middle point (jun 8).
        fireEvent.pointerMove(chart, {
            clientX: 150,
            pointerId: 1,
            pointerType: 'mouse',
        });

        expect(screen.getByText(/jun 8 · 51:57/)).toBeInTheDocument();
    });

    it('ignores a touch move that never touched down (page scroll passes through)', () => {
        mockChartRect();
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);
        const chart = screen.getByRole('slider');

        fireEvent.pointerMove(chart, {
            clientX: 150,
            pointerId: 1,
            pointerType: 'touch',
        });

        expect(screen.queryByText(/jun 8/)).not.toBeInTheDocument();
    });

    it('omits the run link when a point carries no activity id', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);
        const chart = screen.getByRole('slider');

        fireEvent.keyDown(chart, { key: 'ArrowLeft' });

        expect(screen.getByText(/jun 8/)).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /open run/ }),
        ).not.toBeInTheDocument();
    });
});
