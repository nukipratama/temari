import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import RaceGoalForm from './RaceGoalForm';

const RACE = {
    race_date: '2026-12-06',
    distance_m: 10_000,
    goal_time_sec: 3_000,
    name: 'Jakarta 10K',
};

const PROJECTION = {
    predicted_sec: 3_100,
    low_sec: 2_900,
    high_sec: 3_300,
    sample_size: 2,
    confidence: 'medium' as const,
};

function lastPostCall() {
    return vi.mocked(router.post).mock.calls.at(-1);
}

describe('RaceGoalForm', () => {
    it('labels itself for creating a race when there is none', () => {
        render(<RaceGoalForm race={null} projection={null} />);

        expect(screen.getByText('Set your race')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Set race' }),
        ).toBeInTheDocument();
    });

    it('labels itself for editing once a race is set', () => {
        render(<RaceGoalForm race={RACE} projection={null} />);

        expect(screen.getByText('Edit your race')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Update race' }),
        ).toBeInTheDocument();
    });

    it('submits the form with distance in meters and goal time in seconds', () => {
        render(<RaceGoalForm race={null} projection={null} />);

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
        fireEvent.change(screen.getByLabelText('Seconds'), {
            target: { value: '30' },
        });
        fireEvent.change(screen.getByLabelText('Name (optional)'), {
            target: { value: 'Christmas 5K' },
        });

        fireEvent.click(screen.getByRole('button', { name: 'Set race' }));

        const call = lastPostCall();
        expect(call?.[0]).toBe('/race');
        expect(call?.[1]).toEqual({
            race_date: '2026-12-25',
            distance_m: 5_000,
            goal_time_sec: 1_530,
            name: 'Christmas 5K',
        });
    });

    it('accepts a custom distance typed directly, not just a preset pill', () => {
        render(<RaceGoalForm race={null} projection={null} />);

        // race_date is required for the native form submit event to fire at
        // all — without it the click never reaches router.post.
        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });
        fireEvent.change(
            screen.getByLabelText('Custom distance in kilometers'),
            { target: { value: '15' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Set race' }));

        expect(lastPostCall()?.[1]).toMatchObject({ distance_m: 15_000 });
    });

    it('sends a null name when left blank', () => {
        render(<RaceGoalForm race={null} projection={null} />);

        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Set race' }));

        expect(lastPostCall()?.[1]).toMatchObject({ name: null });
    });

    it('shows a saving state between the router request starting and finishing', () => {
        render(<RaceGoalForm race={null} projection={null} />);

        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Set race' }));

        const options = lastPostCall()?.[2] as {
            onStart: () => void;
            onFinish: () => void;
        };

        act(() => options.onStart());
        expect(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();

        act(() => options.onFinish());
        expect(
            screen.getByRole('button', { name: 'Set race' }),
        ).not.toBeDisabled();
    });

    it('refuses to submit a goal time the server would reject outright', () => {
        render(<RaceGoalForm race={null} projection={null} />);

        fireEvent.change(screen.getByLabelText('Minutes'), {
            target: { value: '0' },
        });

        expect(screen.getByRole('button', { name: 'Set race' })).toBeDisabled();
        expect(
            screen.getByText('Goal time has to be at least 5 minutes.'),
        ).toBeInTheDocument();
    });

    it('stops the date picker short of a race day the server would reject', () => {
        render(<RaceGoalForm race={null} projection={null} />);

        const min = screen.getByLabelText('Race day').getAttribute('min') ?? '';

        expect(min).toMatch(/^\d{4}-\d{2}-\d{2}$/);
        expect(new Date(`${min}T23:59:59`).getTime()).toBeGreaterThan(
            Date.now(),
        );
    });

    it('warns without blocking submission when the goal implies an implausible pace', () => {
        render(<RaceGoalForm race={RACE} projection={PROJECTION} />);

        // 10K in 25:00 = 150 sec/km, under the world-record-pace floor.
        fireEvent.change(screen.getByLabelText('Minutes'), {
            target: { value: '25' },
        });
        fireEvent.change(screen.getByLabelText('Seconds'), {
            target: { value: '0' },
        });

        expect(
            screen.getByText(/quicker than world-record pace/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Update race' }),
        ).not.toBeDisabled();
    });

    it("warns when the goal is well ahead of the athlete's own projected range for the same distance", () => {
        render(<RaceGoalForm race={RACE} projection={PROJECTION} />);

        // 10K in 33:20 = 200 sec/km: a plausible pace, but well inside
        // PERSONALIZED_STRETCH_RATIO of the projection's own low_sec (2,900).
        fireEvent.change(screen.getByLabelText('Minutes'), {
            target: { value: '33' },
        });
        fireEvent.change(screen.getByLabelText('Seconds'), {
            target: { value: '20' },
        });

        expect(
            screen.getByText(/well ahead of your own projected range/),
        ).toBeInTheDocument();
    });

    it('stays quiet about ambition once the custom distance no longer matches the projection', () => {
        render(<RaceGoalForm race={RACE} projection={PROJECTION} />);

        fireEvent.change(screen.getByLabelText('Minutes'), {
            target: { value: '33' },
        });
        fireEvent.change(screen.getByLabelText('Seconds'), {
            target: { value: '20' },
        });
        fireEvent.change(
            screen.getByLabelText('Custom distance in kilometers'),
            { target: { value: '15' } },
        );

        expect(
            screen.queryByText(/well ahead of your own projected range/),
        ).not.toBeInTheDocument();
    });

    it('pre-fills from the active race for editing', () => {
        render(<RaceGoalForm race={RACE} projection={null} />);

        expect(
            (screen.getByLabelText('Race day') as HTMLInputElement).value,
        ).toBe('2026-12-06');
        expect(
            (screen.getByLabelText('Name (optional)') as HTMLInputElement)
                .value,
        ).toBe('Jakarta 10K');
        expect(
            (screen.getByLabelText('Minutes') as HTMLInputElement).value,
        ).toBe('50');
    });
});
