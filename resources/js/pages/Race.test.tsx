import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Race from './Race';

const RACE = {
    id: 1,
    race_date: '2026-12-06',
    distance_m: 10_000,
    goal_time_sec: 3_000,
    name: 'Jakarta 10K',
};

const PROJECTION = {
    predicted_sec: 3_100,
    low_sec: 2_900,
    high_sec: 3_300,
    exponent: 1.06,
    sample_size: 2,
    confidence: 'medium' as const,
    window: 'recent' as const,
};

describe('Race', () => {
    it('leads with the duel under a compact header once a race is set', () => {
        const { container } = render(
            <Race race={RACE} projection={PROJECTION} />,
        );

        const headings = [
            'Race',
            'your race.',
            'plan',
            'your goal',
            'on track for',
            'Jakarta 10K',
            'edit your race',
        ];
        const text = container.textContent ?? '';
        const positions = headings.map((h) => text.indexOf(h));

        expect(positions.every((p) => p >= 0)).toBe(true);
        expect(positions).toEqual([...positions].sort((a, b) => a - b));
    });

    it('shows the empty state when no race is set', () => {
        render(<Race race={null} projection={null} />);

        expect(
            screen.getByText('no race on the calendar yet.'),
        ).toBeInTheDocument();
        expect(screen.queryByText('your goal')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'set race' }),
        ).toBeInTheDocument();
    });

    it('keeps the goal form on the page whether or not a race is set', () => {
        const { rerender } = render(<Race race={null} projection={null} />);
        expect(screen.getByText('set your race')).toBeInTheDocument();

        rerender(<Race race={RACE} projection={PROJECTION} />);
        expect(screen.getByText('edit your race')).toBeInTheDocument();
    });

    it('links across to the plan instead of drawing the schedule tabs', () => {
        render(<Race race={null} projection={null} />);

        expect(screen.getByRole('link', { name: 'plan' })).toHaveAttribute(
            'href',
            '/plan',
        );
        expect(screen.queryByText('race goal')).not.toBeInTheDocument();
    });

    it('shows the goal alone when the race has no projection yet', () => {
        render(<Race race={RACE} projection={null} />);

        expect(
            screen.getByText('not enough recent runs to project yet'),
        ).toBeInTheDocument();
    });

    it('draws no fitness chart — that block is cut (P26)', () => {
        const { container } = render(
            <Race race={RACE} projection={PROJECTION} />,
        );

        expect(container.querySelector('canvas')).toBeNull();
        expect(screen.queryByText(/CTL|ATL/)).not.toBeInTheDocument();
        expect(screen.queryByText(/^Fitness/i)).not.toBeInTheDocument();
    });

    it('draws Temari only as the duel card watermark when a race is set', () => {
        const { container } = render(
            <Race race={RACE} projection={PROJECTION} />,
        );

        const mascots = container.querySelectorAll('svg[data-mascot]');
        expect(mascots).toHaveLength(1);
        expect(mascots[0]).toHaveAttribute('width', '200');
    });

    it('draws Temari only in the empty state when no race is set', () => {
        const { container } = render(<Race race={null} projection={null} />);

        const mascots = container.querySelectorAll('svg[data-mascot]');
        expect(mascots).toHaveLength(1);
        expect(mascots[0]).toHaveAttribute('data-mascot', 'sleepy');
    });

    it('states the gap between the goal and the projection', () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        expect(screen.getByText('1:40 behind')).toBeInTheDocument();
    });

    /**
     * Only rendered when a race exists — there is nothing to clear otherwise,
     * and a control that cannot work should not be drawn at all.
     */
    it('offers to clear the race, and only when one is set', () => {
        const { unmount } = render(
            <Race race={RACE} projection={PROJECTION} />,
        );
        expect(
            screen.getByRole('button', { name: /clear this race/i }),
        ).toBeInTheDocument();
        unmount();

        render(<Race race={null} projection={null} />);
        expect(
            screen.queryByRole('button', { name: /clear this race/i }),
        ).not.toBeInTheDocument();
    });

    it('clears through to the race endpoint', () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
        render(<Race race={RACE} projection={PROJECTION} />);

        fireEvent.click(
            screen.getByRole('button', { name: /clear this race/i }),
        );

        expect(remove).toHaveBeenCalledWith('/race');
        remove.mockRestore();
    });
});
