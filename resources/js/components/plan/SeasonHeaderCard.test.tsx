import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import SeasonHeaderCard, { phasesOf } from './SeasonHeaderCard';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        type: 'history',
        planned_km: 30,
        actual_km: null,
        sessions: 5,
        ...overrides,
    };
}

const RACE_SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-06-15', phase: 'base', planned_km: 30 }),
    week({
        week_start: '2026-06-22',
        phase: 'build',
        planned_km: 40,
        type: 'current',
    }),
    week({
        week_start: '2026-06-29',
        phase: 'peak',
        planned_km: 50,
        type: 'lookahead',
    }),
];

function renderCard(
    overrides: Partial<Parameters<typeof SeasonHeaderCard>[0]> = {},
) {
    return render(
        <SeasonHeaderCard
            weekIndex={2}
            totalWeeks={12}
            startsAt="2026-06-15"
            endsAt="2026-09-04"
            adherencePct={82}
            weeks={RACE_SEASON}
            narration={null}
            {...overrides}
        />,
    );
}

describe('phasesOf', () => {
    it('averages each phase’s weekly volume', () => {
        const phases = phasesOf([
            week({ phase: 'base', planned_km: 30 }),
            week({ week_start: '2026-06-22', phase: 'base', planned_km: 40 }),
        ]);

        expect(phases).toEqual([{ key: 'base', avgKm: 35, state: 'done' }]);
    });

    it('marks the phase holding the current week as current', () => {
        expect(phasesOf(RACE_SEASON).map((p) => p.state)).toEqual([
            'done',
            'current',
            'upcoming',
        ]);
    });

    it('keeps a self-scaled season’s repeating build/deload cycle as two phases, not four', () => {
        const phases = phasesOf([
            week({ phase: 'build', type: 'history' }),
            week({
                week_start: '2026-06-22',
                phase: 'deload',
                type: 'current',
            }),
            week({
                week_start: '2026-06-29',
                phase: 'build',
                type: 'lookahead',
            }),
        ]);

        expect(phases.map((p) => p.key)).toEqual(['build', 'deload']);
    });
});

describe('SeasonHeaderCard', () => {
    it('places the athlete in the season', () => {
        renderCard();

        expect(screen.getByText('Season · Week 2 of 12')).toBeInTheDocument();
        expect(screen.getByText('Build · Jun 15 – Sep 4')).toBeInTheDocument();
    });

    it('shows the season adherence figure', () => {
        renderCard();

        expect(screen.getByText('82%')).toBeInTheDocument();
        expect(screen.getByText('Adherence')).toBeInTheDocument();
    });

    it('omits adherence entirely when nothing has been scored yet', () => {
        renderCard({ adherencePct: null });

        expect(screen.queryByText('Adherence')).not.toBeInTheDocument();
    });

    it('draws one labelled bar per phase, tallest at the biggest volume', () => {
        const { container } = renderCard();

        expect(screen.getByText('Base')).toBeInTheDocument();
        expect(screen.getByText('Build')).toBeInTheDocument();
        expect(screen.getByText('Peak')).toBeInTheDocument();

        const bars = container.querySelectorAll('.rounded-t-xs');
        expect(bars).toHaveLength(3);
        expect(bars[0]).toHaveStyle({ height: '35%' });
        expect(bars[2]).toHaveStyle({ height: '100%' });
    });

    it('renders Temari’s take when the season narration exists', () => {
        renderCard({
            narration: {
                id: 1,
                status: 'done',
                content: 'Base held together.',
                type: 'plan_season_voice',
                is_zone_dependent: false,
                subject_type: 'season',
                subject_id: 1,
                discriminator: null,
            } as AnalysisPayload,
        });

        expect(screen.getByText("Temari's take")).toBeInTheDocument();
        expect(screen.getByText('Base held together.')).toBeInTheDocument();
    });
});
