import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { ActiveRace, TrainingLoad } from '@/types/inertia';

import type { FitnessTrendPoint } from './panels/FitnessPanel';

import RaceComparison from './RaceComparison';

function series(ctls: number[]): FitnessTrendPoint[] {
    return ctls.map((ctl, i) => ({
        date: `2026-01-${String(i + 1).padStart(2, '0')}`,
        atl: ctl,
        ctl,
        form_status: 'optimal',
    }));
}

function race(overrides: Partial<ActiveRace> = {}): ActiveRace {
    return {
        id: 1,
        race_date: '2099-11-08',
        distance_m: 10_000,
        goal_time_sec: 3120,
        name: 'Bandung 10K',
        ...overrides,
    };
}

function load(overrides: Partial<TrainingLoad> = {}): TrainingLoad {
    return {
        form: -18.5,
        form_status: 'fatigued',
        form_known_from: '2026-01-01',
        ctl_42d: 42.8,
        atl_7d: 61.3,
        weekly_trimp: 246,
        weekly_trimp_range: { low: 200, high: 300 },
        monotony: 1.9,
        monotony_range: { low: 1.4, high: 2.2 },
        strain: 467,
        strain_range: { low: 350, high: 550 },
        ...overrides,
    };
}

describe('RaceComparison', () => {
    it('labels the section with the race name and date when a race is set', () => {
        render(
            <RaceComparison
                activeRace={race()}
                trend={series([40, 41, 42])}
                load={load()}
            />,
        );

        expect(
            screen.getByText(/vs race day · \d+ days out/),
        ).toBeInTheDocument();
        expect(screen.getByText(/Bandung 10K, nov 8/)).toBeInTheDocument();
    });

    it('states target time and pace, and fitness now', () => {
        render(
            <RaceComparison
                activeRace={race()}
                trend={series([40, 41, 42])}
                load={load()}
            />,
        );

        expect(screen.getByText('target')).toBeInTheDocument();
        expect(screen.getByText('52:00')).toBeInTheDocument();
        expect(screen.getByText(/10\.0 km at 5:12\/km/)).toBeInTheDocument();
        expect(screen.getByText('fitness now')).toBeInTheDocument();
        expect(screen.getByText('load balance today')).toBeInTheDocument();
        expect(screen.getByText('heavy')).toBeInTheDocument();
    });

    it('falls back to "vs your own year" with a set-a-race link when there is no race', () => {
        render(
            <RaceComparison
                activeRace={null}
                trend={series([50, 45, 60, 55])}
                load={load()}
            />,
        );

        expect(screen.getByText('vs your own year')).toBeInTheDocument();
        expect(screen.getByText('best this year')).toBeInTheDocument();
        expect(screen.getByText('60.0')).toBeInTheDocument();
        expect(screen.getByText('today')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /set a race/ }),
        ).toHaveAttribute('href', '/race');
    });

    it('renders an em dash for fitness figures when the trend is empty', () => {
        render(<RaceComparison activeRace={null} trend={[]} load={null} />);

        expect(screen.getAllByText('—')).toHaveLength(2);
    });
});

describe('RaceComparison during the form warm-up', () => {
    it('reads load balance today as learning rather than a verdict', () => {
        render(
            <RaceComparison
                activeRace={race()}
                trend={series([40, 41, 42])}
                load={load({ form_status: null })}
            />,
        );

        expect(screen.getByText('learning')).toBeInTheDocument();
    });
});
