import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    initialOnboardingDraft,
    type OnboardingDraft,
} from '@/hooks/useOnboardingDraft';

import PreferencesStep from './PreferencesStep';

function renderStep(patch: Partial<OnboardingDraft> = {}) {
    const dispatch = vi.fn();
    render(
        <PreferencesStep
            draft={{ ...initialOnboardingDraft, step: 'preferences', ...patch }}
            dispatch={dispatch}
        />,
    );

    return dispatch;
}

describe('Onboarding/PreferencesStep', () => {
    it('dispatches the chosen experience level', () => {
        const dispatch = renderStep();

        fireEvent.click(screen.getByRole('button', { name: 'experienced' }));

        expect(dispatch).toHaveBeenCalledWith({
            type: 'chooseExperience',
            value: 'experienced',
        });
    });

    it('dispatches the chosen sessions per week', () => {
        const dispatch = renderStep({ subIndex: 1 });

        fireEvent.click(screen.getByRole('button', { name: '4x' }));

        expect(dispatch).toHaveBeenCalledWith({
            type: 'chooseSessions',
            value: 4,
        });
    });

    it('dispatches the chosen goal type and a skip of that question', () => {
        const dispatch = renderStep({ subIndex: 2 });

        fireEvent.click(screen.getByRole('button', { name: 'build a base' }));
        fireEvent.click(screen.getByRole('button', { name: 'skip this' }));

        expect(dispatch).toHaveBeenCalledWith({
            type: 'chooseGoalType',
            value: 'base',
        });
        expect(dispatch).toHaveBeenCalledWith({ type: 'skipGoalQuestion' });
    });

    it('caps the run days at the sessions target and gates the long run', () => {
        const dispatch = renderStep({
            subIndex: 3,
            sessionsPerWeek: 2,
            runDays: [0, 2],
        });

        expect(screen.getByRole('button', { name: 'Fri' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'continue' })).toBeDisabled();

        // The second row of day buttons is the long-run picker.
        fireEvent.click(screen.getAllByRole('button', { name: 'Wed' })[1]);
        expect(dispatch).toHaveBeenCalledWith({
            type: 'setLongRunDay',
            offset: 2,
        });
    });

    it('skips the whole step and goes back', () => {
        const dispatch = renderStep();

        fireEvent.click(screen.getByRole('button', { name: 'skip for now' }));
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));

        expect(dispatch).toHaveBeenCalledWith({ type: 'skipPreferences' });
        expect(dispatch).toHaveBeenCalledWith({
            type: 'goBackFromPreferences',
        });
    });
});
