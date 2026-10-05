import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import TimeTrialPrompt from './TimeTrialPrompt';

const TRIAL = { id: 42, date: '2026-10-06', distance_m: 5_000 };

describe('TimeTrialPrompt', () => {
    it('asks by weekday and distance whether the run was the all-out trial', () => {
        render(<TimeTrialPrompt trial={TRIAL} />);

        expect(
            screen.getByText("was Tuesday's 5K your all-out trial?"),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'yes, count it' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: "no, it wasn't all-out" }),
        ).toBeEnabled();
    });

    it('posts the answer for this trial', () => {
        render(<TimeTrialPrompt trial={{ ...TRIAL, distance_m: 10_000 }} />);

        expect(
            screen.getByText("was Tuesday's 10K your all-out trial?"),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'yes, count it' }));
        expect(vi.mocked(router.post).mock.calls.at(-1)?.slice(0, 2)).toEqual([
            '/plan/time-trials/42',
            { all_out: true },
        ]);

        fireEvent.click(
            screen.getByRole('button', { name: "no, it wasn't all-out" }),
        );
        expect(vi.mocked(router.post).mock.calls.at(-1)?.slice(0, 2)).toEqual([
            '/plan/time-trials/42',
            { all_out: false },
        ]);
    });

    it('holds both answers while one is being sent', () => {
        vi.mocked(router.post).mockImplementationOnce(
            (_url, _data, options) => {
                options?.onStart?.({} as never);
            },
        );
        render(<TimeTrialPrompt trial={TRIAL} />);

        fireEvent.click(screen.getByRole('button', { name: 'yes, count it' }));

        expect(
            screen.getByRole('button', { name: 'yes, count it' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: "no, it wasn't all-out" }),
        ).toBeDisabled();
    });

    it('falls back to plain words for an unreadable date', () => {
        render(<TimeTrialPrompt trial={{ ...TRIAL, date: 'soon' }} />);

        expect(
            screen.getByText("was that day's 5K your all-out trial?"),
        ).toBeInTheDocument();
    });
});
