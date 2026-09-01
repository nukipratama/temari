import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import RaceCard from './RaceCard';

describe('RaceCard', () => {
    it('draws the race name, distance and goal time', () => {
        render(
            <RaceCard
                name="Jakarta 10K"
                raceDate="2026-12-06"
                distanceM={10_000}
                goalTimeSec={3_000}
            />,
        );

        expect(screen.getByText('Jakarta 10K')).toBeInTheDocument();
        expect(screen.getByText('10.0 km')).toBeInTheDocument();
        expect(screen.getByText('50:00')).toBeInTheDocument();
        expect(screen.getByText('Distance')).toBeInTheDocument();
        expect(screen.getByText('Goal time')).toBeInTheDocument();
    });

    it('falls back to a generic title when the race was saved unnamed', () => {
        render(
            <RaceCard
                name={null}
                raceDate="2026-12-06"
                distanceM={21_100}
                goalTimeSec={7_200}
            />,
        );

        expect(screen.getByText('Your race')).toBeInTheDocument();
    });

    it('counts the days left up to the real countdown', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-26T12:00:00'));

        render(
            <RaceCard
                name="Jakarta 10K"
                raceDate="2026-12-06"
                distanceM={10_000}
                goalTimeSec={3_000}
            />,
        );

        // The initial render already captured the countdown target under the
        // fake system time; switch back to real timers so the count-up's
        // animation frame loop (tier-2, tallies up from 0) can actually settle.
        vi.useRealTimers();
        await waitFor(() =>
            expect(screen.getByText(/10 days to go/)).toBeInTheDocument(),
        );
    });
});
