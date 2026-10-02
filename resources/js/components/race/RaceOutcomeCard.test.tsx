import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PastRace } from '@/types/inertia';

import RaceOutcomeCard from './RaceOutcomeCard';

function pastRace(overrides: Partial<PastRace['outcome']> = {}): PastRace {
    return {
        id: 7,
        race_date: '2026-10-04',
        distance_m: 10_000,
        goal_time_sec: 3_000,
        name: 'Jakarta 10K',
        outcome: {
            state: 'pending',
            finish_time_sec: null,
            activity_id: null,
            recorded_at: null,
            suggestion: null,
            ...overrides,
        },
    };
}

function lastPostCall() {
    return vi.mocked(router.post).mock.calls.at(-1);
}

describe('RaceOutcomeCard', () => {
    it('asks neutrally and claims nothing while the outcome is pending', () => {
        render(<RaceOutcomeCard race={pastRace()} />);

        expect(
            screen.getByText('waiting for you to say how it went'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'i did not run it' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'confirm this run' }),
        ).not.toBeInTheDocument();
    });

    it('offers the matched run pre-filled and confirms it by activity', () => {
        render(
            <RaceOutcomeCard
                race={pastRace({
                    suggestion: {
                        activity_id: 99,
                        name: 'Race day',
                        distance_m: 10_040,
                        elapsed_time_sec: 2_950,
                        started_at: '2026-10-04 07:00:00',
                    },
                })}
            />,
        );

        expect(screen.getByText(/10\.04 km/)).toBeInTheDocument();
        expect(screen.getByText(/49:10/)).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'confirm this run' }),
        );

        expect(lastPostCall()?.[0]).toBe('/race/7/outcome');
        expect(lastPostCall()?.[1]).toEqual({
            outcome: 'confirmed',
            activity_id: 99,
        });
    });

    it('confirms a typed finish time in seconds', () => {
        render(<RaceOutcomeCard race={pastRace()} />);

        const save = screen.getByRole('button', { name: 'save time' });
        expect(save).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Finish minutes'), {
            target: { value: '51' },
        });
        fireEvent.change(screen.getByLabelText('Finish seconds'), {
            target: { value: '30' },
        });
        fireEvent.click(save);

        expect(lastPostCall()?.[1]).toEqual({
            outcome: 'confirmed',
            finish_time_sec: 3_090,
        });
    });

    it('marks did not run', () => {
        render(<RaceOutcomeCard race={pastRace()} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'i did not run it' }),
        );

        expect(lastPostCall()?.[1]).toEqual({ outcome: 'did_not_run' });
    });

    it('collapses to the saved result and reopens on "change"', () => {
        render(
            <RaceOutcomeCard
                race={pastRace({ state: 'confirmed', finish_time_sec: 3_090 })}
            />,
        );

        expect(screen.getByText(/result saved · 51:30/)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'i did not run it' }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'change' }));
        expect(
            screen.getByRole('button', { name: 'i did not run it' }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'keep as is' }));
        expect(
            screen.queryByRole('button', { name: 'i did not run it' }),
        ).not.toBeInTheDocument();
    });

    it('uses neutral wording for a race that was not run or called off', () => {
        const { rerender } = render(
            <RaceOutcomeCard race={pastRace({ state: 'did_not_run' })} />,
        );
        expect(screen.getByText('marked as not run')).toBeInTheDocument();

        rerender(<RaceOutcomeCard race={pastRace({ state: 'cancelled' })} />);
        expect(screen.getByText('marked as called off')).toBeInTheDocument();
    });

    it('closes the change panel after a successful save', async () => {
        render(<RaceOutcomeCard race={pastRace({ state: 'did_not_run' })} />);
        fireEvent.click(screen.getByRole('button', { name: 'change' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'i did not run it' }),
        );

        const options = lastPostCall()?.[2];
        await act(async () => {
            options?.onStart?.({} as never);
            options?.onSuccess?.({} as never);
            options?.onFinish?.({} as never);
        });

        expect(
            screen.queryByRole('button', { name: 'keep as is' }),
        ).not.toBeInTheDocument();
    });
});
