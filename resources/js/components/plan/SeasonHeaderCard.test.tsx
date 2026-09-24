import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import SeasonHeaderCard from './SeasonHeaderCard';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        zone: 'block',
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

const GENERAL_ZONE_CURRENT: SeasonSummaryWeek[] = [
    week({
        week_start: '2026-05-25',
        zone: 'general',
        phase: 'build',
        type: 'current',
    }),
    week({
        week_start: '2026-06-01',
        zone: 'general',
        phase: 'deload',
        type: 'lookahead',
    }),
    week({
        week_start: '2026-06-08',
        zone: 'block',
        phase: 'base',
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

function openBand() {
    fireEvent.click(screen.getByRole('button', { name: /^Week \d+ of \d+/ }));
}

describe('SeasonHeaderCard', () => {
    it('places the athlete in the season', () => {
        renderCard();

        expect(screen.getByText('Week 2 of 12 · build')).toBeInTheDocument();
        expect(screen.queryByText('jun 15 – sep 4')).not.toBeInTheDocument();

        openBand();

        expect(screen.getByText('jun 15 – sep 4')).toBeInTheDocument();
    });

    it('shows the season adherence figure', () => {
        renderCard();

        expect(screen.getByText('82%')).toBeInTheDocument();
        expect(screen.getByText(/adherence/)).toBeInTheDocument();
    });

    it('omits adherence entirely when nothing has been scored yet', () => {
        renderCard({ adherencePct: null });

        expect(screen.queryByText(/adherence/)).not.toBeInTheDocument();
    });

    it('draws one labelled bar per phase, tallest at the biggest volume', () => {
        const { container } = renderCard();
        openBand();

        expect(screen.getByText('base')).toBeInTheDocument();
        expect(screen.getByText('build')).toBeInTheDocument();
        expect(screen.getByText('peak')).toBeInTheDocument();

        const bars = container.querySelectorAll('.rounded-t-xs');
        expect(bars).toHaveLength(3);
        expect(bars[0]).toHaveStyle({ height: '35%' });
        expect(bars[2]).toHaveStyle({ height: '100%' });
    });

    it('states the under-ready line when the season carries one', () => {
        const line =
            "Twelve weeks is tighter than I'd pick for this one, so we build what we can and race what we've built.";

        renderCard({ underReadyLine: line });
        openBand();

        expect(screen.getByText(line)).toBeInTheDocument();
    });

    it('says nothing about readiness without the line', () => {
        renderCard({ underReadyLine: null });
        openBand();

        expect(screen.queryByText(/tighter than/)).not.toBeInTheDocument();
    });

    it('draws the phase ribbon for a season with a race block', () => {
        renderCard();

        expect(
            screen.getByRole('button', { name: 'build, current week' }),
        ).toBeInTheDocument();
    });

    it('omits the phase ribbon for a season with no race block', () => {
        renderCard({
            weeks: RACE_SEASON.map((w) => ({ ...w, zone: 'general' })),
        });

        expect(
            screen.queryByRole('button', { name: 'build' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'maintain' }),
        ).not.toBeInTheDocument();
    });

    it('names the general zone in the header, spanning only the general run, while the current week is in it', () => {
        renderCard({
            startsAt: '2026-05-25',
            endsAt: '2026-08-10',
            weeks: GENERAL_ZONE_CURRENT,
        });
        openBand();

        // Not the raw phase ('build'), and not the whole season's span
        // ('2026-05-25 – 2026-08-10') — just the general run's own dates.
        expect(screen.getByText('Week 2 of 12 · maintain')).toBeInTheDocument();
        expect(screen.getByText('may 25 – jun 7')).toBeInTheDocument();
        expect(screen.queryByText(/· build$/)).not.toBeInTheDocument();
    });

    it('adds a "maintain" legend entry first, filled while the current week is in it', () => {
        renderCard({ weeks: GENERAL_ZONE_CURRENT });
        openBand();

        expect(screen.getByText('maintain')).toBeInTheDocument();
        expect(screen.queryByText('build')).not.toBeInTheDocument();
        expect(screen.queryByText('deload')).not.toBeInTheDocument();
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
        expect(screen.queryByText("Temari's take")).not.toBeInTheDocument();

        openBand();

        expect(screen.getByText("Temari's take")).toBeInTheDocument();
        expect(screen.getByText('base held together.')).toBeInTheDocument();
    });
});
