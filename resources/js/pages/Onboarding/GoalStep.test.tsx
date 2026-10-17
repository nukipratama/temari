import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    initialOnboardingDraft,
    type OnboardingDraft,
} from '@/hooks/useOnboardingDraft';
import { setMockPage } from '@/test/setup';

import GoalStep from './GoalStep';

function renderStep(
    patch: Partial<OnboardingDraft> = {},
    errors: Record<string, string> = {},
) {
    setMockPage({});
    const dispatch = vi.fn();
    render(
        <GoalStep
            draft={{ ...initialOnboardingDraft, step: 'goal', ...patch }}
            dispatch={dispatch}
            errors={errors}
        />,
    );

    return dispatch;
}

describe('Onboarding/GoalStep', () => {
    it('shows the required pace for the draft goal', () => {
        renderStep({ distanceKm: 10, minutes: 50 });

        expect(screen.getByText('5:00/km')).toBeInTheDocument();
    });

    it('recaps answered preferences', () => {
        renderStep({ sessionsPerWeek: 3 });

        expect(screen.getByText('got it: 3x a week.')).toBeInTheDocument();
    });

    it('keeps set my goal disabled until there is a race day', () => {
        renderStep();

        expect(
            screen.getByRole('button', { name: 'set my goal' }),
        ).toBeDisabled();
    });

    it('dispatches submit and skip', () => {
        const dispatch = renderStep({ raceDate: '2026-12-25' });

        fireEvent.click(screen.getByRole('button', { name: 'set my goal' }));
        fireEvent.click(screen.getByRole('button', { name: 'skip for now' }));

        expect(dispatch).toHaveBeenCalledWith({ type: 'submitGoal' });
        expect(dispatch).toHaveBeenCalledWith({ type: 'skipGoal' });
    });

    it('dispatches edits to the name and race day', () => {
        const dispatch = renderStep();

        fireEvent.change(screen.getByLabelText('name (optional)'), {
            target: { value: 'Half' },
        });
        fireEvent.change(screen.getByLabelText('race day'), {
            target: { value: '2026-12-25' },
        });

        expect(dispatch).toHaveBeenCalledWith({
            type: 'setName',
            value: 'Half',
        });
        expect(dispatch).toHaveBeenCalledWith({
            type: 'setRaceDate',
            value: '2026-12-25',
        });
    });

    it('renders server errors beside their fields', () => {
        renderStep({}, { race_date: 'Race day has to be in the future.' });

        expect(
            screen.getByText('Race day has to be in the future.'),
        ).toBeInTheDocument();
    });
});
