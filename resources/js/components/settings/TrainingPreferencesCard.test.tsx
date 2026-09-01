import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import TrainingPreferencesCard, {
    type TrainingPreferencesPayload,
} from './TrainingPreferencesCard';

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

describe('TrainingPreferencesCard', () => {
    // The prototype draws this as an always-open card, not a disclosure: every
    // control is reachable without a trigger click.
    it('renders every control without needing to be expanded', () => {
        render(<TrainingPreferencesCard trainingPreferences={EMPTY_PAYLOAD} />);

        expect(screen.getByText('Training preferences')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'experienced' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '5x' })).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'chase a race time' }),
        ).toBeInTheDocument();
    });

    it('seeds every control from the current stored preferences', () => {
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);

        expect(
            screen.getByRole('button', { name: 'experienced' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: '5x' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(
            screen.getByRole('button', { name: 'chase a race time' }),
        ).toHaveAttribute('aria-pressed', 'true');
    });

    it('caps day selection at the chosen session count', () => {
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);

        // Five of seven are already picked, so the two unpicked days are shut.
        expect(
            screen.getAllByRole('button', { name: 'Wed' })[0],
        ).toBeDisabled();
    });

    it('hides the long-run picker when no run days are set', () => {
        render(<TrainingPreferencesCard trainingPreferences={EMPTY_PAYLOAD} />);
        expect(
            screen.queryByText(/Which one.s the long run/),
        ).not.toBeInTheDocument();
    });

    it('shows the long-run picker once run days exist', () => {
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);
        expect(
            screen.getByText(/Which one.s the long run/),
        ).toBeInTheDocument();
    });

    it('keeps the long-run day when a different run day is swapped out', () => {
        vi.mocked(router.patch).mockReset();
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);

        // Mon is a run day but not the long run; swapping it for Wed leaves
        // Sun as the long run.
        fireEvent.click(screen.getAllByRole('button', { name: 'Mon' })[0]);
        fireEvent.click(screen.getAllByRole('button', { name: 'Wed' })[0]);
        fireEvent.click(screen.getByRole('button', { name: /Save changes/ }));

        expect(router.patch).toHaveBeenCalledWith(
            '/settings/training-preferences',
            expect.objectContaining({ long_run_day: 6 }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('drops the long-run day from both rows when that day is unpicked', () => {
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);

        // Sun appears twice: once in the run-day row, once in the long-run row.
        expect(screen.getAllByRole('button', { name: 'Sun' })).toHaveLength(2);

        fireEvent.click(screen.getAllByRole('button', { name: 'Sun' })[0]);

        expect(screen.queryAllByRole('button', { name: 'Sun' })).toHaveLength(
            1,
        );
        expect(screen.getByText(/4 of 5 selected/)).toBeInTheDocument();
    });

    it('disables Save until something actually changed', () => {
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);

        expect(
            screen.getByRole('button', { name: /Save changes/ }),
        ).toBeDisabled();

        fireEvent.click(
            screen.getByRole('button', { name: 'stay consistent' }),
        );
        expect(
            screen.getByRole('button', { name: /Save changes/ }),
        ).toBeEnabled();
    });

    it('resets the day picks when the session count changes', () => {
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);

        fireEvent.click(screen.getByRole('button', { name: '3x' }));

        expect(
            screen.queryByText(/Which one.s the long run/),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/0 of 3 selected/)).toBeInTheDocument();
    });

    it('patches the full preference payload on save', () => {
        vi.mocked(router.patch).mockReset();
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);

        fireEvent.click(screen.getByRole('button', { name: 'build a base' }));
        fireEvent.click(screen.getByRole('button', { name: /Save changes/ }));

        expect(router.patch).toHaveBeenCalledWith(
            '/settings/training-preferences',
            {
                experience_level: 'experienced',
                sessions_per_week: 5,
                goal_type: 'base',
                run_days: [0, 1, 3, 5, 6],
                long_run_day: 6,
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('flashes Saved once the patch succeeds', () => {
        vi.mocked(router.patch).mockReset();
        render(<TrainingPreferencesCard trainingPreferences={SET_PAYLOAD} />);

        fireEvent.click(screen.getByRole('button', { name: 'build a base' }));
        fireEvent.click(screen.getByRole('button', { name: /Save changes/ }));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        const [, , options] = vi.mocked(router.patch).mock.calls[0] as [
            string,
            unknown,
            {
                onStart?: () => void;
                onSuccess?: () => void;
                onFinish?: () => void;
            },
        ];
        act(() => {
            options.onStart?.();
            options.onSuccess?.();
            options.onFinish?.();
        });

        expect(screen.getByRole('status')).toHaveTextContent('Saved');
    });

    it('sends null run_days when every day has been cleared', () => {
        vi.mocked(router.patch).mockReset();
        render(
            <TrainingPreferencesCard
                trainingPreferences={{
                    ...EMPTY_PAYLOAD,
                    sessions_per_week: 2,
                    run_days: [0],
                }}
            />,
        );

        fireEvent.click(screen.getAllByRole('button', { name: 'Mon' })[0]);
        fireEvent.click(screen.getByRole('button', { name: /Save changes/ }));

        expect(router.patch).toHaveBeenCalledWith(
            '/settings/training-preferences',
            expect.objectContaining({ run_days: null }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
