import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import Plan from './Plan';

const TODAY = '2026-08-10';

const DAY = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    date: TODAY,
    phase: 'build',
    session_type: 'easy',
    distance_band: 'medium',
    pace_band: 'easy',
    pace_sec_per_km: 330,
    distance_km: 8.0,
    pinned: false,
    status: 'planned',
    clamp_note: null,
    ...overrides,
});

const WEEK = (overrides: Record<string, unknown> = {}) => ({
    week_start: TODAY,
    phase: 'build',
    type: 'current' as const,
    days: [DAY()],
    ...overrides,
});

const SEASON = {
    starts_at: TODAY,
    ends_at: '2026-11-02',
    week_index: 1,
    total_weeks: 12,
    is_race_oriented: false,
    goals: [],
};

function lastPatchCall() {
    return vi.mocked(router.patch).mock.calls.at(-1);
}

function lastPostCall() {
    return vi.mocked(router.post).mock.calls.at(-1);
}

function lastDeleteCall() {
    return vi.mocked(router.delete).mock.calls.at(-1);
}

describe('Plan', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date(`${TODAY}T08:00:00`));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows an empty state with no weeks generated yet', () => {
        render(
            <Plan race={null} sessionsPerWeek={3} season={SEASON} weeks={[]} />,
        );

        expect(screen.getByText('No plan yet.')).toBeInTheDocument();
    });

    it("renders a session's type, distance, and pace", () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        expect(screen.getByText(/Easy/)).toBeInTheDocument();
        expect(screen.getByText(/8 km/)).toBeInTheDocument();
        expect(screen.getByText(/5:30\/km/)).toBeInTheDocument();
    });

    it('shows the readiness-clamp explanation when present', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[
                    WEEK({
                        days: [
                            DAY({
                                clamp_note:
                                    "Your form dipped, so today's the easy version instead.",
                            }),
                        ],
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/Your form dipped/)).toBeInTheDocument();
    });

    it('links to /race, mentioning a set race by name', () => {
        render(
            <Plan
                race={{ race_date: '2026-12-06', name: 'Jakarta 10K' }}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[]}
            />,
        );

        expect(screen.getByText(/Jakarta 10K/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Change your race' }),
        ).toHaveAttribute('href', '/race');
    });

    it('offers to set a race when there is none', () => {
        render(
            <Plan race={null} sessionsPerWeek={3} season={SEASON} weeks={[]} />,
        );

        expect(
            screen.getByRole('link', { name: 'Set a race' }),
        ).toHaveAttribute('href', '/race');
    });

    it('posts to /plan/regenerate on Regenerate', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Regenerate' }));

        expect(lastPostCall()?.[0]).toBe('/plan/regenerate');
    });

    it('pins a day', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Pin' }));

        expect(lastPatchCall()?.[0]).toBe('/plan/sessions/1');
        expect(lastPatchCall()?.[1]).toEqual({ pinned: true });
    });

    it('unpins an already-pinned day', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK({ days: [DAY({ pinned: true })] })]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Unpin' }));

        expect(lastPatchCall()?.[1]).toEqual({ pinned: false });
    });

    it('blocks a training day to rest', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Block' }));

        expect(lastPatchCall()?.[1]).toEqual({ session_type: 'rest' });
    });

    it('restores a rest day back to easy', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[
                    WEEK({
                        days: [
                            DAY({
                                session_type: 'rest',
                                distance_band: 'rest',
                                pace_band: null,
                                pace_sec_per_km: null,
                            }),
                        ],
                    }),
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Restore' }));

        expect(lastPatchCall()?.[1]).toEqual({
            session_type: 'easy',
            distance_band: 'medium',
            pace_band: 'easy',
        });
    });

    it('cycles the distance band on Resize', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Resize' }));

        expect(lastPatchCall()?.[1]).toEqual({ distance_band: 'long' });
    });

    it('does not offer Resize for a rest day', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK({ days: [DAY({ session_type: 'rest' })] })]}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Resize' }),
        ).not.toBeInTheDocument();
    });

    it('deletes a day', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Delete/ }));

        expect(lastDeleteCall()?.[0]).toBe('/plan/sessions/1');
    });

    it('moves a day to a new date', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK()]}
            />,
        );

        fireEvent.change(screen.getByLabelText(`Move ${TODAY}`), {
            target: { value: '2026-08-15' },
        });

        expect(lastPatchCall()?.[1]).toEqual({ date: '2026-08-15' });
    });

    it('renders the season arc progress and a link to the badge board', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={{
                    ...SEASON,
                    week_index: 3,
                    total_weeks: 12,
                }}
                weeks={[]}
            />,
        );

        expect(screen.getByText(/Week 3 of 12/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Badge board' }),
        ).toHaveAttribute('href', '/badges');
    });

    it("renders each season goal's title and progress", () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={{
                    ...SEASON,
                    goals: [
                        {
                            id: 1,
                            title: 'Complete your planned sessions',
                            current: 3,
                            target: 10,
                            unit: 'sessions',
                            is_completed: false,
                        },
                    ],
                }}
                weeks={[]}
            />,
        );

        expect(
            screen.getByText('Complete your planned sessions'),
        ).toBeInTheDocument();
        expect(
            screen.getByText((_, el) => el?.textContent === '3/10'),
        ).toBeInTheDocument();
    });

    it('hides edit controls for history weeks', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK({ type: 'history', week_start: '2026-07-27' })]}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Pin' }),
        ).not.toBeInTheDocument();
    });

    it('shows the current week’s phase as the season visual caption', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[WEEK({ phase: 'peak' })]}
            />,
        );

        // "Peak" also labels the per-week chip in the schedule below, so
        // assert on the season caption text instead, which is unique.
        expect(
            screen.getByText(/most intricate the pattern gets/),
        ).toBeInTheDocument();
    });

    it('pauses season-visual accretion on a deload week instead of resetting it', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                season={SEASON}
                weeks={[
                    WEEK({
                        week_start: '2026-07-27',
                        phase: 'build',
                        type: 'history',
                    }),
                    WEEK({ phase: 'deload' }),
                ]}
            />,
        );

        // The deload week borrows the last non-deload phase (build) rather
        // than falling back to base — asserted via the build caption, since
        // "Build" also labels the history week's own chip below.
        expect(
            screen.getByText(/Coverage building, bands starting to lock in/),
        ).toBeInTheDocument();
    });

    it('falls back to the base season phase when no current week exists', () => {
        render(
            <Plan race={null} sessionsPerWeek={4} season={SEASON} weeks={[]} />,
        );

        expect(screen.getByText('Base')).toBeInTheDocument();
    });
});
