import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import OnboardingIndex from './Index';

function lastPostCall() {
    return vi.mocked(router.post).mock.calls.at(-1);
}

/** Connected -> preferences, then straight past preferences to the goal step. */
function advanceToGoal() {
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }));
}

describe('Onboarding/Index', () => {
    it('shows the connected step with the user first name', () => {
        setMockPage({ auth: { user: makeUser({ first_name: 'Budi' }) } });

        render(<OnboardingIndex />);

        expect(screen.getByText(/You.re connected, Budi/)).toBeInTheDocument();
    });

    it('advances to the preferences step on continue', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByText('Tell us how you train.')).toBeInTheDocument();
    });

    it('advances to the goal step from preferences', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        advanceToGoal();

        expect(screen.getByText('Got a race in mind?')).toBeInTheDocument();
    });

    it('disables the finish button until a race day is entered', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        advanceToGoal();

        expect(
            screen.getByRole('button', { name: 'Set my goal & finish' }),
        ).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });

        expect(
            screen.getByRole('button', { name: 'Set my goal & finish' }),
        ).not.toBeDisabled();
    });

    it('submits the goal with distance in meters and goal time in seconds', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        advanceToGoal();

        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });
        fireEvent.click(screen.getByRole('button', { name: '5K' }));
        fireEvent.change(screen.getByLabelText('Hours'), {
            target: { value: '2' },
        });
        fireEvent.change(screen.getByLabelText('Minutes'), {
            target: { value: '25' },
        });
        fireEvent.change(screen.getByLabelText('Name (optional)'), {
            target: { value: 'Christmas 5K' },
        });

        fireEvent.click(
            screen.getByRole('button', { name: 'Set my goal & finish' }),
        );

        const call = lastPostCall();
        expect(call?.[0]).toBe('/onboarding');
        expect(call?.[1]).toEqual({
            race_date: '2026-12-25',
            distance_m: 5_000,
            goal_time_sec: 8_700,
            name: 'Christmas 5K',
        });
    });

    it('shows a saving state while the submission is in flight', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        advanceToGoal();

        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Set my goal & finish' }),
        );

        const [, , options] = vi.mocked(router.post).mock.calls.at(-1) as [
            string,
            Record<string, unknown>,
            { onStart?: () => void; onFinish?: () => void },
        ];
        act(() => options.onStart?.());
        expect(
            screen.getByRole('button', { name: 'Saving…' }),
        ).toBeInTheDocument();

        act(() => options.onFinish?.());
        expect(
            screen.getByRole('button', { name: 'Set my goal & finish' }),
        ).toBeInTheDocument();
    });

    it('sets the promise before the ask: what landed, and what is still coming', () => {
        setMockPage({ auth: { user: makeUser() } });

        render(<OnboardingIndex />);

        expect(
            screen.getByText(/Every run Strava already has for you is landing/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/is fetched per run, the first time you open it/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/what every run you do from here gets measured/),
        ).toBeInTheDocument();
    });

    it('refuses to submit a goal time the server would reject outright', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        advanceToGoal();

        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });
        fireEvent.change(screen.getByLabelText('Minutes'), {
            target: { value: '0' },
        });

        expect(
            screen.getByRole('button', { name: 'Set my goal & finish' }),
        ).toBeDisabled();
        expect(
            screen.getByText('Goal time has to be at least 5 minutes.'),
        ).toBeInTheDocument();
    });

    it('stops the date picker short of a race day the server would reject', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        advanceToGoal();

        const min = screen.getByLabelText('Race day').getAttribute('min') ?? '';

        expect(min).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(new Date(`${min}T23:59:59`).getTime()).toBeGreaterThan(
            Date.now(),
        );
    });

    it('surfaces a server field error next to the field that caused it', () => {
        setMockPage({
            auth: { user: makeUser() },
            errors: { race_date: 'Race day has to be in the future.' },
        });
        render(<OnboardingIndex />);
        advanceToGoal();

        expect(
            screen.getByText('Race day has to be in the future.'),
        ).toBeInTheDocument();
    });

    it('skips with an empty payload when nothing was ever entered', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        advanceToGoal();

        fireEvent.change(screen.getByLabelText('Name (optional)'), {
            target: { value: 'Half-typed idea' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }));

        const call = lastPostCall();
        expect(call?.[0]).toBe('/onboarding');
        expect(call?.[1]).toEqual({});
    });

    it('lets the preferences step be skipped entirely, discarding any partial picks', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        fireEvent.click(screen.getByRole('button', { name: 'New to running' }));
        fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }));
        fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }));

        const call = lastPostCall();
        expect(call?.[1]).toEqual({});
    });

    it('caps run-day selection at the chosen sessions-per-week count', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        fireEvent.click(screen.getByRole('button', { name: '2x' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Wed' }));

        expect(screen.getByRole('button', { name: 'Fri' })).toBeDisabled();
    });

    it('deselects an already-picked day on a second click', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        fireEvent.click(screen.getByRole('button', { name: '2x' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));

        expect(
            screen.getByText('Pick 2 — 0 of 2 selected.'),
        ).toBeInTheDocument();
    });

    it('reveals the long-run picker only once the day count matches the target', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        fireEvent.click(screen.getByRole('button', { name: '2x' }));
        expect(
            screen.queryByText(/Which one.s your long run\?/),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Wed' }));

        expect(
            screen.getByText(/Which one.s your long run\?/),
        ).toBeInTheDocument();
    });

    it('submits the chosen training preferences alongside the race goal', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        fireEvent.click(screen.getByRole('button', { name: 'New to running' }));
        fireEvent.click(screen.getByRole('button', { name: '2x' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Stay consistent' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Wed' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Wed' }).at(-1)!);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }));

        const call = lastPostCall();
        expect(call?.[1]).toEqual({
            experience_level: 'new_to_running',
            sessions_per_week: 2,
            goal_type: 'consistent',
            run_days: [0, 2],
            long_run_day: 2,
        });
    });
});
