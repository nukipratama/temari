import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { makeUser, setMockPage } from '@/test/setup';

import OnboardingIndex from './Index';

function lastPostCall() {
    return vi.mocked(router.post).mock.calls.at(-1);
}

describe('Onboarding/Index', () => {
    it('shows the connected step with the user first name', () => {
        setMockPage({ auth: { user: makeUser({ first_name: 'Budi' }) } });

        render(<OnboardingIndex />);

        expect(screen.getByText(/You.re connected, Budi/)).toBeInTheDocument();
    });

    it('advances to the goal step on continue', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByText('Got a race in mind?')).toBeInTheDocument();
    });

    it('disables the finish button until a race day is entered', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

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
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });
        fireEvent.click(screen.getByRole('button', { name: '5K' }));
        fireEvent.change(screen.getByLabelText('Hours'), {
            target: { value: '0' },
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
            goal_time_sec: 1_500,
            name: 'Christmas 5K',
        });
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
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

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
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

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
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(
            screen.getByText('Race day has to be in the future.'),
        ).toBeInTheDocument();
    });

    it('skips with an empty payload regardless of unsaved form input', () => {
        setMockPage({ auth: { user: makeUser() } });
        render(<OnboardingIndex />);
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        fireEvent.change(screen.getByLabelText('Name (optional)'), {
            target: { value: 'Half-typed idea' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Skip for now' }));

        const call = lastPostCall();
        expect(call?.[0]).toBe('/onboarding');
        expect(call?.[1]).toEqual({});
    });
});
