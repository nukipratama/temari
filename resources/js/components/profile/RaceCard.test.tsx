import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import RaceCard from './RaceCard';

const race = {
    id: 1,
    race_date: '2026-10-12',
    distance_m: 21_100,
    goal_time_sec: 6_300,
    name: 'Jakarta Half Marathon',
};

describe('RaceCard', () => {
    it('prompts for a race when none is set', () => {
        render(<RaceCard race={null} />);

        expect(screen.getByText('Got a race coming up?')).toBeInTheDocument();
        expect(screen.getByRole('link')).toHaveAttribute('href', '/race');
    });

    it('shows the race, its distance, its date and the days left', () => {
        setMockPage({ today: '2026-08-31' });

        render(<RaceCard race={race} />);

        expect(screen.getByText('Jakarta Half Marathon')).toBeInTheDocument();
        expect(screen.getByText(/21\.1 km/)).toBeInTheDocument();
        expect(screen.getByText('42')).toBeInTheDocument();
        expect(screen.getByText('days')).toBeInTheDocument();
    });

    it('falls back to a generic title when the race is unnamed', () => {
        render(<RaceCard race={{ ...race, name: null }} />);

        expect(screen.getByText('Your race')).toBeInTheDocument();
    });

    it('says "day" on the eve of the race', () => {
        setMockPage({ today: '2026-10-11' });

        render(<RaceCard race={race} />);

        expect(screen.getByText('day')).toBeInTheDocument();
    });
});
