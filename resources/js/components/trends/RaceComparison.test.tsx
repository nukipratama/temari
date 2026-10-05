import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { ActiveRace, TrainingLoad } from '@/types/inertia';

import RaceComparison from './RaceComparison';

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
            <RaceComparison activeRace={race()} outlook={null} load={load()} />,
        );

        expect(
            screen.getByText(/vs race day · \d+ days out/),
        ).toBeInTheDocument();
        expect(screen.getByText(/Bandung 10K, nov 8/)).toBeInTheDocument();
    });

    it('sets the target beside the time recent runs support, with no second long-term load hero', () => {
        render(
            <RaceComparison
                activeRace={race()}
                outlook={{
                    ambition: {
                        state: 'ambitious',
                        target_time_sec: 3120,
                        target_pace_sec_per_km: 312,
                        supported_time_sec: 3270,
                        supported_pace_sec_per_km: 327,
                        prescribed_time_sec: 3120,
                        gap_pct: 4.6,
                        evidence_confidence: 'confirmed',
                        basis: {
                            distance_m: 10_000,
                            performed_on: '2026-09-20',
                            activity_id: null,
                        },
                    },
                    support: {
                        mode: 'road',
                        dedicated_preparation: true,
                        limitation: null,
                    },
                }}
                load={load()}
            />,
        );

        expect(screen.getByText('your target')).toBeInTheDocument();
        expect(screen.getByText('52:00')).toBeInTheDocument();
        expect(screen.getByText(/10\.0 km at 5:12\/km/)).toBeInTheDocument();
        expect(
            screen.getByText('supported by your recent runs'),
        ).toBeInTheDocument();
        expect(screen.getByText('54:30')).toBeInTheDocument();
        expect(
            screen.getByText('based on your 10K on sep 20'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/^ambitious: your target is 4\.6% faster/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/long-term load now|fitness now/),
        ).not.toBeInTheDocument();
        expect(screen.getByText('load balance today')).toBeInTheDocument();
        expect(screen.getByText('heavy')).toBeInTheDocument();
    });

    it('shows the same low-evidence and empty states as /race', () => {
        const ambition = {
            state: 'low_evidence' as const,
            target_time_sec: 3120,
            target_pace_sec_per_km: 312,
            supported_time_sec: 3270,
            supported_pace_sec_per_km: 327,
            prescribed_time_sec: 3270,
            gap_pct: 4.6,
            evidence_confidence: 'confirmed',
            basis: null,
        };
        const support = {
            mode: 'road' as const,
            dedicated_preparation: true,
            limitation: null,
        };
        const { rerender } = render(
            <RaceComparison
                activeRace={race()}
                outlook={{ ambition, support }}
                load={load()}
            />,
        );
        expect(screen.getByText(/^low evidence: /)).toBeInTheDocument();

        rerender(
            <RaceComparison
                activeRace={race()}
                outlook={{
                    ambition: {
                        ...ambition,
                        state: 'unknown',
                        supported_time_sec: null,
                        supported_pace_sec_per_km: null,
                        gap_pct: null,
                    },
                    support,
                }}
                load={load()}
            />,
        );
        expect(
            screen.queryByText('supported by your recent runs'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('not enough recent results to compare yet.'),
        ).toBeInTheDocument();
    });

    it('offers a set-a-race link and repeats no long-term load figure when there is no race', () => {
        render(
            <RaceComparison activeRace={null} outlook={null} load={load()} />,
        );

        expect(screen.getByText('vs race day')).toBeInTheDocument();
        expect(screen.getByText(/no race set/)).toBeInTheDocument();
        expect(screen.queryByText('best this year')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /set a race/ }),
        ).toHaveAttribute('href', '/race');
    });
});

describe('RaceComparison during the form warm-up', () => {
    it('reads load balance today as learning rather than a verdict', () => {
        render(
            <RaceComparison
                activeRace={race()}
                outlook={null}
                load={load({ form_status: null })}
            />,
        );

        expect(screen.getByText('learning')).toBeInTheDocument();
    });
});
