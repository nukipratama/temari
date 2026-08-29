import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import TrainingPreferencesDisclosure, {
    type TrainingPreferencesPayload,
} from './TrainingPreferencesDisclosure';

const EMPTY_PAYLOAD: TrainingPreferencesPayload = {
    experience_level: null,
    sessions_per_week: null,
    goal_type: null,
    run_days: null,
    long_run_day: null,
};

const SET_PAYLOAD: TrainingPreferencesPayload = {
    experience_level: 'experienced',
    sessions_per_week: 5,
    goal_type: 'race',
    run_days: [0, 1, 3, 5, 6],
    long_run_day: 6,
};

function open() {
    fireEvent.click(
        screen.getByRole('button', { name: /Training preferences/ }),
    );
}

describe('TrainingPreferencesDisclosure', () => {
    it('stays collapsed until the trigger is clicked, naming the fallback when nothing is set', () => {
        render(
            <TrainingPreferencesDisclosure
                trainingPreferences={EMPTY_PAYLOAD}
            />,
        );

        expect(screen.getByText('Training preferences')).toBeInTheDocument();
        expect(
            screen.getByText(/Temari uses your recent activity instead/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Experienced' }),
        ).not.toBeInTheDocument();

        open();
        expect(
            screen.getByRole('button', { name: 'Experienced' }),
        ).toBeInTheDocument();
    });

    it('names the sessions-per-week count when collapsed and something is set', () => {
        render(
            <TrainingPreferencesDisclosure trainingPreferences={SET_PAYLOAD} />,
        );

        expect(screen.getByText('5x a week, your way')).toBeInTheDocument();
    });

    it('seeds every control from the current stored preferences', () => {
        render(
            <TrainingPreferencesDisclosure trainingPreferences={SET_PAYLOAD} />,
        );
        open();

        expect(screen.getByRole('button', { name: 'Experienced' })).toHaveClass(
            'border-horizon',
        );
        expect(screen.getByRole('button', { name: '5x' })).toHaveClass(
            'border-horizon',
        );
        expect(
            screen.getByRole('button', { name: 'Chase a race time' }),
        ).toHaveClass('border-horizon');
        expect(
            screen.getAllByRole('button', { name: 'Sun' }).at(-1),
        ).toHaveClass('border-horizon');
    });

    it('disables Save until something actually changed', () => {
        render(
            <TrainingPreferencesDisclosure
                trainingPreferences={EMPTY_PAYLOAD}
            />,
        );
        open();

        expect(
            screen.getByRole('button', { name: /Save preferences/ }),
        ).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Experienced' }));
        expect(
            screen.getByRole('button', { name: /Save preferences/ }),
        ).toBeEnabled();
    });

    it('caps run-day selection at the chosen sessions-per-week count', () => {
        render(
            <TrainingPreferencesDisclosure
                trainingPreferences={EMPTY_PAYLOAD}
            />,
        );
        open();

        fireEvent.click(screen.getByRole('button', { name: '2x' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Wed' }));

        expect(screen.getByRole('button', { name: 'Fri' })).toBeDisabled();
    });

    it('reveals the long-run-day picker only once the day count matches the target', () => {
        render(
            <TrainingPreferencesDisclosure
                trainingPreferences={EMPTY_PAYLOAD}
            />,
        );
        open();

        fireEvent.click(screen.getByRole('button', { name: '2x' }));
        expect(screen.queryByText('Long run day')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Wed' }));

        expect(screen.getByText('Long run day')).toBeInTheDocument();
    });

    it('deselects an already-picked day on a second click', () => {
        render(
            <TrainingPreferencesDisclosure
                trainingPreferences={EMPTY_PAYLOAD}
            />,
        );
        open();

        fireEvent.click(screen.getByRole('button', { name: '2x' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));

        expect(
            screen.getByText('Pick 2 — 0 of 2 selected.'),
        ).toBeInTheDocument();
    });

    it('flags the chosen long-run day from the picker', () => {
        render(
            <TrainingPreferencesDisclosure
                trainingPreferences={EMPTY_PAYLOAD}
            />,
        );
        open();

        fireEvent.click(screen.getByRole('button', { name: '2x' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Wed' }));
        fireEvent.click(screen.getAllByRole('button', { name: 'Wed' }).at(-1)!);

        expect(
            screen.getAllByRole('button', { name: 'Wed' }).at(-1),
        ).toHaveClass('border-horizon');
    });

    it('flashes Saved and clears the flash timeout on the round trip and on unmount', () => {
        vi.mocked(router.patch).mockReset();
        const { unmount } = render(
            <TrainingPreferencesDisclosure
                trainingPreferences={EMPTY_PAYLOAD}
            />,
        );
        open();

        fireEvent.click(screen.getByRole('button', { name: 'Experienced' }));
        fireEvent.click(
            screen.getByRole('button', { name: /Save preferences/ }),
        );

        const [, , options] = vi.mocked(router.patch).mock.calls.at(-1) as [
            string,
            Record<string, unknown>,
            {
                onStart?: () => void;
                onFinish?: () => void;
                onSuccess?: () => void;
            },
        ];
        act(() => options.onStart?.());
        act(() => options.onSuccess?.());
        expect(screen.getByRole('status')).toHaveTextContent('Saved');
        act(() => options.onFinish?.());

        unmount();
    });

    it('submits the full preferences payload on save', () => {
        vi.mocked(router.patch).mockReset();
        render(
            <TrainingPreferencesDisclosure trainingPreferences={SET_PAYLOAD} />,
        );
        open();

        fireEvent.click(screen.getByRole('button', { name: '4x' }));
        fireEvent.click(screen.getByRole('button', { name: 'Mon' }));
        fireEvent.click(screen.getByRole('button', { name: 'Tue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Wed' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sat' }));
        fireEvent.click(
            screen.getByRole('button', { name: /Save preferences/ }),
        );

        expect(router.patch).toHaveBeenCalledWith(
            '/settings/training-preferences',
            expect.objectContaining({
                experience_level: 'experienced',
                sessions_per_week: 4,
                goal_type: 'race',
                run_days: [0, 1, 2, 5],
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('clears a field back to null by toggling it off', () => {
        vi.mocked(router.patch).mockReset();
        render(
            <TrainingPreferencesDisclosure trainingPreferences={SET_PAYLOAD} />,
        );
        open();

        fireEvent.click(screen.getByRole('button', { name: 'Experienced' }));
        fireEvent.click(
            screen.getByRole('button', { name: /Save preferences/ }),
        );

        expect(router.patch).toHaveBeenCalledWith(
            '/settings/training-preferences',
            expect.objectContaining({ experience_level: null }),
            expect.anything(),
        );
    });
});
