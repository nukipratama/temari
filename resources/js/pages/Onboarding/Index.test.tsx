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
