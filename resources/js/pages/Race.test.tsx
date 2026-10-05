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

import type { PastRace, RaceDetails } from '@/types/inertia';

import { setMockPage } from '@/test/setup';

import Race from './Race';

const RACE: RaceDetails = {
    id: 1,
    race_date: '2026-12-06',
    distance_m: 10_000,
    goal_time_sec: 3_000,
    name: 'Jakarta 10K',
    ambition: {
        state: 'on_track',
        target_time_sec: 3_000,
        target_pace_sec_per_km: 300,
        supported_time_sec: 3_050,
        supported_pace_sec_per_km: 305,
        prescribed_time_sec: 3_000,
        gap_pct: 1.6,
        evidence_confidence: 'confirmed',
        basis: null,
        confirm_nudge: false,
    },
    support: { mode: 'road', dedicated_preparation: true, limitation: null },
    history: [],
};

const PAST_RACE: PastRace = {
    id: 9,
    race_date: '2026-10-04',
    distance_m: 21_097,
    goal_time_sec: 7_000,
    name: 'Bandung Half',
    outcome: {
        state: 'pending',
        finish_time_sec: null,
        activity_id: null,
        recorded_at: null,
        suggestion: null,
    },
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
    it('asks for a confirmed effort under the duel when the supported time needs one', () => {
        render(
            <Race
                race={{
                    ...RACE,
                    ambition: {
                        ...RACE.ambition,
                        evidence_confidence: 'provisional',
                        basis: {
                            distance_m: 5_000,
                            performed_on: '2026-08-26',
                            activity_id: 42,
                        },
                        confirm_nudge: true,
                    },
                }}
                projection={PROJECTION}
            />,
        );

        expect(
            screen.getByText('based on your 5K on aug 26'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'open the run' }),
        ).toHaveAttribute('href', '/activities/42');
    });

    it('shows nothing about an AI pause, since it renders no narration', () => {
        setMockPage({ aiPaused: true });

        render(Race.layout(<Race race={RACE} projection={PROJECTION} />));

        expect(
            screen.queryByText(/catching her breath/),
        ).not.toBeInTheDocument();
    });

    it('leads with the duel under a compact header once a race is set', () => {
        const { container } = render(
            <Race race={RACE} projection={PROJECTION} />,
        );

        const headings = [
            'Race',
            'your race.',
            'plan',
            'your target',
            'supported',
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
            screen.getByText(/set a race and temari compares your target/),
        ).toBeInTheDocument();
        expect(screen.queryByText('your target')).not.toBeInTheDocument();
        expect(screen.queryByText('set your race')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /clear race/i }),
        ).not.toBeInTheDocument();
    });

    it('expands the goal form from "set a race"', async () => {
        render(<Race race={null} projection={null} />);

        const toggle = screen.getByRole('button', { name: 'set a race' });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        fireEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(toggle).toHaveAttribute('aria-controls', 'race-goal-form');
        expect(await screen.findByText('set your race')).toBeInTheDocument();
    });

    it('keeps the goal form collapsed until "edit race" opens it', async () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        expect(screen.queryByText('edit your race')).not.toBeInTheDocument();
        const toggle = screen.getByRole('button', { name: 'edit race' });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(toggle);

        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(await screen.findByText('edit your race')).toBeInTheDocument();
    });

    it('collapses the form again after a successful save', async () => {
        render(<Race race={RACE} projection={PROJECTION} />);
        fireEvent.click(screen.getByRole('button', { name: 'edit race' }));

        fireEvent.click(
            await screen.findByRole('button', { name: 'update race' }),
        );
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
        expect(screen.getByRole('link', { name: 'plan' })).toHaveClass(
            'hit-area',
        );
    });

    it('gives clear race a padded hit area', () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        expect(screen.getByRole('button', { name: 'clear race' })).toHaveClass(
            'hit-area',
        );
    });

    it('shows the target alone when recent runs support no time yet', () => {
        render(
            <Race
                race={{
                    ...RACE,
                    ambition: {
                        ...RACE.ambition,
                        state: 'unknown',
                        supported_time_sec: null,
                        supported_pace_sec_per_km: null,
                        gap_pct: null,
                    },
                }}
                projection={null}
            />,
        );

        expect(
            screen.getByText('not enough recent results to compare yet.'),
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

    it('states the gap between the target and the supported time', () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        expect(screen.getByText('0:50 behind')).toBeInTheDocument();
    });

    it('asks through a concerned Temari before clearing the race', async () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        fireEvent.click(screen.getByRole('button', { name: 'clear race' }));

        const dialog = await screen.findByRole('dialog');
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
            within(await screen.findByRole('dialog')).getByRole('button', {
                name: 'keep it',
            }),
        );

        await waitFor(() => {
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        });
        expect(remove).not.toHaveBeenCalled();
        remove.mockRestore();
    });

    it('clears through to the race endpoint once confirmed', async () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => {});
        render(<Race race={{ ...RACE, name: null }} projection={PROJECTION} />);

        fireEvent.click(screen.getByRole('button', { name: 'clear race' }));
        const dialog = await screen.findByRole('dialog');
        expect(
            within(dialog).getByText('clear your race?'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'clear race' }),
        );

        expect(remove).toHaveBeenCalledWith('/race');
        remove.mockRestore();
    });

    it('draws no outcome card when no race has passed', () => {
        render(<Race race={RACE} projection={PROJECTION} />);

        expect(
            screen.queryByRole('region', { name: /outcome/ }),
        ).not.toBeInTheDocument();
    });

    it('asks how a passed race went, even with no active race', () => {
        render(<Race race={null} projection={null} past_races={[PAST_RACE]} />);

        expect(
            screen.getByRole('region', { name: 'Bandung Half outcome' }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'i did not run it' }),
        );

        expect(vi.mocked(router.post).mock.calls.at(-1)?.[0]).toBe(
            '/race/9/outcome',
        );
    });
});
