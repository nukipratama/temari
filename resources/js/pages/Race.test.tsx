import { router } from '@inertiajs/react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Race from './Race';

const RACE = {
    id: 1,
    race_date: '2026-12-06',
    distance_m: 10_000,
    goal_time_sec: 3_000,
    name: 'Jakarta 10K',
};

const PROJECTION = {
    predicted_sec: 3_100,
    low_sec: 2_900,
    high_sec: 3_300,
    exponent: 1.06,
    sample_size: 2,
    confidence: 'medium' as const,
};

function lastPostCall() {
    return vi.mocked(router.post).mock.calls.at(-1);
}

describe('Race', () => {
    it('shows the empty state and default form when there is no race', () => {
        render(<Race race={null} projection={null} ctlTrend={[]} />);

        expect(
            screen.getByText('No race on the calendar yet.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Set race' }),
        ).toBeInTheDocument();
    });

    it('renders the active race summary and projection', async () => {
        render(<Race race={RACE} projection={PROJECTION} ctlTrend={[]} />);

        expect(screen.getByText('Jakarta 10K')).toBeInTheDocument();
        expect(screen.getByText('10.0')).toBeInTheDocument();
        expect(screen.getByText('50:00')).toBeInTheDocument();
        expect(screen.getByText(/2 PRs/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Update race' }),
        ).toBeInTheDocument();
        // The projected low/high figures tally up from 0 (tier-2 count-up),
        // so wait for them to settle.
        await waitFor(() => {
            expect(screen.getByText(/48:20/)).toBeInTheDocument();
            expect(screen.getByText(/55:00/)).toBeInTheDocument();
        });
    });

    it('explains there is no projection yet when the race has no PR to anchor from', () => {
        render(<Race race={RACE} projection={null} ctlTrend={[]} />);

        expect(screen.getByText(/No personal record yet/)).toBeInTheDocument();
    });

    it('shows how many days remain before the race', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-26T12:00:00'));

        render(<Race race={RACE} projection={null} ctlTrend={[]} />);

        // The initial render already captured the countdown target under the
        // fake system time; switch back to real timers so the count-up's
        // animation frame loop (tier-2, tallies up from 0) can actually settle.
        vi.useRealTimers();
        await waitFor(() =>
            expect(screen.getByText('10 days to go')).toBeInTheDocument(),
        );
    });

    it('submits the form with distance in meters and goal time in seconds', () => {
        render(<Race race={null} projection={null} ctlTrend={[]} />);

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

    it('sends a null name when left blank', () => {
        render(<Race race={null} projection={null} ctlTrend={[]} />);

        fireEvent.change(screen.getByLabelText('Race day'), {
            target: { value: '2026-12-25' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Set race' }));

        const call = lastPostCall();
        expect(call?.[1]).toMatchObject({ name: null });
    });

    it('pre-fills the form from the active race for editing', () => {
        render(<Race race={RACE} projection={null} ctlTrend={[]} />);

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
