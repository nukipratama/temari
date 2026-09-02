import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import SeasonHeaderCard from './SeasonHeaderCard';

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

describe('SeasonHeaderCard', () => {
    it('places the athlete in the season', () => {
        renderCard();

        expect(screen.getByText('Season · Week 2 of 12')).toBeInTheDocument();
        expect(screen.getByText('build · jun 15 – sep 4')).toBeInTheDocument();
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

        expect(screen.getByText('base')).toBeInTheDocument();
        expect(screen.getByText('build')).toBeInTheDocument();
        expect(screen.getByText('peak')).toBeInTheDocument();

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
                content: 'base held together.',
                type: 'plan_season_voice',
                is_zone_dependent: false,
                subject_type: 'season',
                subject_id: 1,
                discriminator: null,
            } as AnalysisPayload,
        });

        expect(screen.getByText("Temari's take")).toBeInTheDocument();
        expect(screen.getByText('base held together.')).toBeInTheDocument();
    });
});
