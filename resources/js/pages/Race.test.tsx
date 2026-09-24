import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
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
            'edit race',
            'clear race',
        ];
        const text = container.textContent ?? '';
        const positions = headings.map((h) => text.indexOf(h));

        expect(positions.every((p) => p >= 0)).toBe(true);
        expect(positions).toEqual([...positions].sort((a, b) => a - b));
    });

    it('shows only a short line and a set-a-race button when no race is set', () => {
        render(<Race race={null} projection={null} />);

        expect(
            screen.getByText(/set a race and temari projects your finish/),
        ).toBeInTheDocument();
        expect(screen.queryByText('your goal')).not.toBeInTheDocument();
        expect(screen.queryByText('set your race')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /clear race/i }),
        ).not.toBeInTheDocument();
    });

    it('expands the goal form from "set a race"', () => {
        render(<Race race={null} projection={null} />);

        const toggle = screen.getByRole('button', { name: 'set a race' });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(toggle).toHaveAttribute('aria-controls', 'race-goal-form');
        expect(screen.getByText('set your race')).toBeInTheDocument();
    });

    it('keeps the goal form collapsed until "edit race" opens it', () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        expect(screen.queryByText('edit your race')).not.toBeInTheDocument();
        const toggle = screen.getByRole('button', { name: 'edit race' });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getByText('edit your race')).toBeInTheDocument();
    });

    it('collapses the form again after a successful save', () => {
        render(<Race race={RACE} projection={PROJECTION} />);
        fireEvent.click(screen.getByRole('button', { name: 'edit race' }));

        fireEvent.click(screen.getByRole('button', { name: 'update race' }));
        act(() => {
            vi.mocked(router.post)
                .mock.calls.at(-1)?.[2]
                ?.onSuccess?.({} as never);
        });

        expect(screen.queryByText('edit your race')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'edit race' }),
        ).toHaveAttribute('aria-expanded', 'false');
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

    it('draws no Temari when no race is set', () => {
        const { container } = render(<Race race={null} projection={null} />);

        expect(container.querySelector('svg[data-mascot]')).toBeNull();
    });

    it('states the gap between the goal and the projection', () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        expect(screen.getByText('1:40 behind')).toBeInTheDocument();
    });

    it('asks through a concerned Temari before clearing the race', () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        fireEvent.click(screen.getByRole('button', { name: 'clear race' }));

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('clear Jakarta 10K?'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/steady rhythm with regular deloads/),
        ).toBeInTheDocument();
        expect(dialog.querySelector('svg[data-mascot]')).toHaveAttribute(
            'data-mascot',
            'concerned',
        );
    });

    it('keeps the race when the confirmation is dismissed', async () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
        render(<Race race={RACE} projection={PROJECTION} />);

        fireEvent.click(screen.getByRole('button', { name: 'clear race' }));
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'keep it',
            }),
        );

        await waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
        expect(remove).not.toHaveBeenCalled();
        remove.mockRestore();
    });

    it('clears through to the race endpoint once confirmed', () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
        render(<Race race={{ ...RACE, name: null }} projection={PROJECTION} />);

        fireEvent.click(screen.getByRole('button', { name: 'clear race' }));
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('clear your race?'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'clear race' }),
        );

        expect(remove).toHaveBeenCalledWith('/race');
        remove.mockRestore();
    });
});
