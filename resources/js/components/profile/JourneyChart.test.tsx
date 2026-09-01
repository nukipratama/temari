import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import JourneyChart from './JourneyChart';

const WEEKS = ['2026-05-25', '2026-06-08', '2026-06-22'];
const TIMES = [3160, 3117, 2895];

describe('JourneyChart', () => {
    it('marks the fastest week as the PR and the rest as plain points', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);

        expect(
            screen.getByRole('button', { name: /jun 22.*personal record/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'may 25: 52:40' }),
        ).toBeInTheDocument();
    });

    it('summarises the span for assistive tech', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);

        expect(
            screen.getByText(
                'Best time journey. From 52:40 on may 25 to 48:15 on jun 22.',
            ),
        ).toBeInTheDocument();
    });

    it('toggles a point tooltip on click and closes it on a second click', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);
        const point = screen.getByRole('button', {
            name: 'jun 8: 51:57',
        });

        fireEvent.click(point);
        expect(screen.getByText(/jun 8 · 51:57/)).toBeInTheDocument();

        fireEvent.click(point);
        expect(screen.queryByText(/jun 8 · 51:57/)).not.toBeInTheDocument();
    });

    it('closes the tooltip on a click outside the chart', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);

        fireEvent.click(screen.getByRole('button', { name: 'jun 8: 51:57' }));
        expect(screen.getByText(/jun 8 · 51:57/)).toBeInTheDocument();

        fireEvent.click(document.body);
        expect(screen.queryByText(/jun 8 · 51:57/)).not.toBeInTheDocument();
    });

    it('opens the tooltip from the keyboard', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={TIMES} />);

        fireEvent.keyDown(
            screen.getByRole('button', { name: 'jun 8: 51:57' }),
            { key: 'Enter' },
        );

        expect(screen.getByText(/jun 8 · 51:57/)).toBeInTheDocument();
    });

    it('draws an empty state when no week carries a time', () => {
        render(<JourneyChart weeks={WEEKS} timesSec={[null, null, null]} />);

        expect(
            screen.getByText(/Not enough runs at this distance yet/),
        ).toBeInTheDocument();
    });

    it('centres a lone point rather than dividing by a zero span', () => {
        const { container } = render(
            <JourneyChart weeks={['2026-05-25']} timesSec={[3160]} />,
        );
        const dot = container.querySelectorAll('circle')[1];

        expect(dot.getAttribute('cx')).toBe('150');
        expect(dot.getAttribute('cy')).toBe('39');
    });
});
