import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload, PlanRaceCard } from '@/types/inertia';

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
            adherencePct={82}
            weeks={RACE_SEASON}
            raceCard={raceCard()}
            narration={null}
            {...overrides}
        />,
    );
}

const NARRATION = {
    id: 1,
    status: 'done',
    content: 'base held together.',
    type: 'plan_season_voice',
    is_zone_dependent: false,
    subject_type: 'season',
    subject_id: 1,
    discriminator: null,
} as AnalysisPayload;

function raceCard(overrides: Partial<PlanRaceCard> = {}): PlanRaceCard {
    return {
        state: 'ahead',
        race_id: 4,
        race_date: '2026-12-06',
        distance_m: 10_000,
        goal_time_sec: 3_600,
        name: null,
        days_to_race: 56,
        finish_time_sec: null,
        ...overrides,
    };
}

describe('SeasonHeaderCard', () => {
    it('invites a race under the meta line when there is none', () => {
        renderCard({ raceCard: null });

        expect(
            screen.getByText(
                'add a race and the plan builds base → build → peak → taper to it.',
            ).parentElement,
        ).toHaveClass('border-dashed');
        expect(
            screen.getByRole('link', { name: '+ add a race' }),
        ).toHaveAttribute('href', '/race');
    });

    it('shows nothing in the race slot while a race is set or its result is pending', () => {
        for (const state of [
            'ahead',
            'week',
            'day',
            'result_pending',
        ] as const) {
            const { unmount } = renderCard({ raceCard: raceCard({ state }) });

            expect(
                screen.queryByRole('link', { name: '+ add a race' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('link', { name: 'next race ›' }),
            ).not.toBeInTheDocument();
            unmount();
        }
    });

    it('states a recorded result on one line, ticked when it beat the goal', () => {
        renderCard({
            raceCard: raceCard({
                state: 'result_recorded',
                days_to_race: null,
                finish_time_sec: 3_521,
            }),
        });

        const line = screen.getByText(/dec 6 · 10K 58:41/);
        expect(line).toHaveTextContent('dec 6 · 10K 58:41 ✓ · next race ›');
        expect(screen.getByText('✓')).toHaveClass('text-horizon-ink');
        expect(
            screen.getByRole('link', { name: 'next race ›' }),
        ).toHaveAttribute('href', '/race');
        expect(
            screen.queryByRole('link', { name: '+ add a race' }),
        ).not.toBeInTheDocument();
    });

    it('leaves the tick off a result slower than the goal', () => {
        renderCard({
            raceCard: raceCard({
                state: 'result_recorded',
                days_to_race: null,
                finish_time_sec: 3_650,
            }),
        });

        expect(screen.getByText(/dec 6 · 10K 60:50/)).toHaveTextContent(
            'dec 6 · 10K 60:50 · next race ›',
        );
    });

    it('places the athlete in the season', () => {
        renderCard();

        expect(screen.getByText('season · week 2 of 12')).toBeInTheDocument();
    });

    it('leads with the current phase as the headline', () => {
        renderCard();

        const headline = screen.getByRole('heading', { level: 2 });

        expect(headline).toHaveTextContent('build week.');
        expect(screen.getByText('week.')).toHaveClass('text-horizon-ink');
    });

    it('names the next phase and how many weeks away it starts', () => {
        renderCard();

        expect(screen.getByText(/next: peak in 1 week\b/)).toBeInTheDocument();
    });

    it('pluralises the weeks until the next phase', () => {
        renderCard({
            weeks: [
                week({ phase: 'base', type: 'current' }),
                week({ week_start: '2026-06-22', phase: 'base' }),
                week({ week_start: '2026-06-29', phase: 'build' }),
            ],
        });

        expect(screen.getByText(/next: build in 2 weeks/)).toBeInTheDocument();
    });

    it('omits "next" when the season stays in the current phase', () => {
        renderCard({
            weeks: [
                week({ phase: 'peak', zone: 'block', type: 'current' }),
                week({ week_start: '2026-06-22', phase: 'peak' }),
            ],
        });

        expect(screen.queryByText(/next:/)).not.toBeInTheDocument();
    });

    it('shows the season adherence figure in the accent colour', () => {
        renderCard();

        expect(screen.getByText('82%')).toHaveClass('text-horizon-ink');
        expect(screen.getByText(/adherence/)).toBeInTheDocument();
    });

    it('joins next and adherence on one meta line, leaving the race countdown to the race card', () => {
        renderCard();

        expect(
            screen.getByText(
                (_, element) =>
                    element?.tagName === 'P' &&
                    element.textContent ===
                        'next: peak in 1 week · 82% adherence',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/race in/)).not.toBeInTheDocument();
    });

    it('omits adherence entirely when nothing has been scored yet', () => {
        renderCard({ adherencePct: null });

        expect(screen.queryByText(/adherence/)).not.toBeInTheDocument();
        expect(screen.getByText(/^next: peak in 1 week$/)).toBeInTheDocument();
    });

    it('draws no meta line when there is nothing to put on it', () => {
        renderCard({
            adherencePct: null,
            weeks: [week({ type: 'current' })],
        });

        expect(screen.queryByText(/next:|adherence|race/)).toBeNull();
    });

    it('is not collapsible and keeps no phase bars or date range', () => {
        const { container } = renderCard();

        expect(screen.queryByRole('button', { name: /^week/ })).toBeNull();
        expect(container.querySelector('[aria-expanded]')).toBeNull();
        expect(container.querySelector('.rounded-t-xs')).toBeNull();
        expect(screen.queryByText(/ – /)).not.toBeInTheDocument();
    });

    it('states the under-ready line when the season carries one', () => {
        const line =
            "Twelve weeks is tighter than I'd pick for this one, so we build what we can and race what we've built.";

        renderCard({ underReadyLine: line });

        expect(screen.getByText(line)).toBeInTheDocument();
    });

    it('says nothing about readiness without the line', () => {
        renderCard({ underReadyLine: null });

        expect(screen.queryByText(/tighter than/)).not.toBeInTheDocument();
    });

    it('draws the phase ribbon for a season with a race block', () => {
        renderCard();

        expect(
            screen.getByRole('button', { name: 'build, current week' }),
        ).toBeInTheDocument();
    });

    it('omits the phase ribbon and reads "maintain week." for a season with no race block', () => {
        renderCard({
            weeks: RACE_SEASON.map((w) => ({ ...w, zone: 'general' })),
        });

        expect(screen.queryAllByRole('button')).toHaveLength(0);
        expect(screen.getByRole('heading', { level: 2 })).toHaveTextContent(
            'maintain week.',
        );
        expect(screen.queryByText(/next:/)).not.toBeInTheDocument();
    });

    it('names the general zone as "maintain" and its coming deload as next', () => {
        renderCard({ weeks: GENERAL_ZONE_CURRENT });

        expect(screen.getByRole('heading', { level: 2 })).toHaveTextContent(
            'maintain week.',
        );
        expect(screen.getByText(/next: deload in 1 week/)).toBeInTheDocument();
        expect(screen.queryByText(/build/)).not.toBeInTheDocument();
    });

    it('reads a general-zone deload week as "deload week.", with maintain next', () => {
        renderCard({
            weeks: [
                {
                    ...GENERAL_ZONE_CURRENT[0],
                    phase: 'deload',
                },
                { ...GENERAL_ZONE_CURRENT[1], phase: 'build' },
            ],
        });

        expect(screen.getByRole('heading', { level: 2 })).toHaveTextContent(
            'deload week.',
        );
        expect(
            screen.getByText(/next: maintain in 1 week/),
        ).toBeInTheDocument();
    });

    it('renders Temari’s take, always visible, when the season narration exists', () => {
        renderCard({ narration: NARRATION });

        expect(screen.getByText("Temari's take")).toBeInTheDocument();
        expect(screen.getByText('base held together.')).toBeInTheDocument();
    });

    it('puts the under-ready line above Temari’s take', () => {
        renderCard({ underReadyLine: 'tight block.', narration: NARRATION });

        const line = screen.getByText('tight block.');
        const take = screen.getByText("Temari's take");

        expect(
            line.compareDocumentPosition(take) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('draws no take without narration', () => {
        renderCard({ narration: null });

        expect(screen.queryByText("Temari's take")).not.toBeInTheDocument();
    });
});
