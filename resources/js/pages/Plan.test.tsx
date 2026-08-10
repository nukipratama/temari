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
        render(<Plan race={null} sessionsPerWeek={3} weeks={[]} />);

        expect(screen.getByText('No plan yet.')).toBeInTheDocument();
    });

    it("renders a session's type, distance, and pace", () => {
        render(<Plan race={null} sessionsPerWeek={4} weeks={[WEEK()]} />);

        expect(screen.getByText(/Easy/)).toBeInTheDocument();
        expect(screen.getByText(/8 km/)).toBeInTheDocument();
        expect(screen.getByText(/5:30\/km/)).toBeInTheDocument();
    });

    it('shows the readiness-clamp explanation when present', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
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
                weeks={[]}
            />,
        );

        expect(screen.getByText(/Jakarta 10K/)).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Change your race' }),
        ).toHaveAttribute('href', '/race');
    });

    it('offers to set a race when there is none', () => {
        render(<Plan race={null} sessionsPerWeek={3} weeks={[]} />);

        expect(
            screen.getByRole('link', { name: 'Set a race' }),
        ).toHaveAttribute('href', '/race');
    });

    it('posts to /plan/regenerate on Regenerate', () => {
        render(<Plan race={null} sessionsPerWeek={4} weeks={[WEEK()]} />);

        fireEvent.click(screen.getByRole('button', { name: 'Regenerate' }));

        expect(lastPostCall()?.[0]).toBe('/plan/regenerate');
    });

    it('pins a day', () => {
        render(<Plan race={null} sessionsPerWeek={4} weeks={[WEEK()]} />);

        fireEvent.click(screen.getByRole('button', { name: 'Pin' }));

        expect(lastPatchCall()?.[0]).toBe('/plan/sessions/1');
        expect(lastPatchCall()?.[1]).toEqual({ pinned: true });
    });

    it('unpins an already-pinned day', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                weeks={[WEEK({ days: [DAY({ pinned: true })] })]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Unpin' }));

        expect(lastPatchCall()?.[1]).toEqual({ pinned: false });
    });

    it('blocks a training day to rest', () => {
        render(<Plan race={null} sessionsPerWeek={4} weeks={[WEEK()]} />);

        fireEvent.click(screen.getByRole('button', { name: 'Block' }));

        expect(lastPatchCall()?.[1]).toEqual({ session_type: 'rest' });
    });

    it('restores a rest day back to easy', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
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
        render(<Plan race={null} sessionsPerWeek={4} weeks={[WEEK()]} />);

        fireEvent.click(screen.getByRole('button', { name: 'Resize' }));

        expect(lastPatchCall()?.[1]).toEqual({ distance_band: 'long' });
    });

    it('does not offer Resize for a rest day', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                weeks={[WEEK({ days: [DAY({ session_type: 'rest' })] })]}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Resize' }),
        ).not.toBeInTheDocument();
    });

    it('deletes a day', () => {
        render(<Plan race={null} sessionsPerWeek={4} weeks={[WEEK()]} />);

        fireEvent.click(screen.getByRole('button', { name: /Delete/ }));

        expect(lastDeleteCall()?.[0]).toBe('/plan/sessions/1');
    });

    it('moves a day to a new date', () => {
        render(<Plan race={null} sessionsPerWeek={4} weeks={[WEEK()]} />);

        fireEvent.change(screen.getByLabelText(`Move ${TODAY}`), {
            target: { value: '2026-08-15' },
        });

        expect(lastPatchCall()?.[1]).toEqual({ date: '2026-08-15' });
    });

    it('hides edit controls for history weeks', () => {
        render(
            <Plan
                race={null}
                sessionsPerWeek={4}
                weeks={[WEEK({ type: 'history', week_start: '2026-07-27' })]}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Pin' }),
        ).not.toBeInTheDocument();
    });
});
