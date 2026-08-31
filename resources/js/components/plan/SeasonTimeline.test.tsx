import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanWeek, SeasonSummaryWeek } from '@/lib/plan';

import SeasonTimeline from './SeasonTimeline';

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

/** Two base weeks behind, the current base week, then a build and a peak week. */
const SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-06-01' }),
    week({ week_start: '2026-06-08' }),
    week({ week_start: '2026-06-15', type: 'current' }),
    week({ week_start: '2026-06-22', phase: 'build', type: 'lookahead' }),
    week({ week_start: '2026-06-29', phase: 'peak', type: 'lookahead' }),
];

const CURRENT_DETAIL: PlanWeek = {
    week_start: '2026-06-15',
    phase: 'base',
    type: 'current',
    days: [],
};

function renderTimeline(
    overrides: Partial<Parameters<typeof SeasonTimeline>[0]> = {},
) {
    return render(
        <SeasonTimeline
            weeks={SEASON}
            detailByWeekStart={{ '2026-06-15': CURRENT_DETAIL }}
            today="2026-06-17"
            weekFocus={null}
            weekNarration={null}
            dayNarration={{}}
            onMove={vi.fn()}
            onSkip={vi.fn()}
            {...overrides}
        />,
    );
}

describe('SeasonTimeline', () => {
    it('renders nothing before a current week exists', () => {
        const { container } = renderTimeline({
            weeks: [week({ type: 'history' })],
        });

        expect(container).toBeEmptyDOMElement();
    });

    it('names the phase the athlete is in', () => {
        renderTimeline();

        expect(screen.getByText('Base phase')).toBeInTheDocument();
    });

    it('folds the weeks already behind in this phase into one cluster', () => {
        renderTimeline();

        expect(screen.getByText('2 weeks behind')).toBeInTheDocument();
        expect(screen.queryByText('Wk 1')).not.toBeInTheDocument();
    });

    it('folds every later phase into one cluster', () => {
        renderTimeline();

        expect(screen.getByText('2 weeks ahead')).toBeInTheDocument();
        expect(screen.queryByText('Build phase')).not.toBeInTheDocument();
    });

    it('singularises a one-week cluster', () => {
        renderTimeline({
            weeks: [
                week({ week_start: '2026-06-08' }),
                week({ week_start: '2026-06-15', type: 'current' }),
            ],
        });

        expect(screen.getByText('1 week behind')).toBeInTheDocument();
    });

    it('replaces the past cluster with its real week rows on request', () => {
        renderTimeline();
        fireEvent.click(screen.getByRole('button', { name: /2 weeks behind/ }));

        expect(screen.getByText('Wk 1')).toBeInTheDocument();
        expect(screen.getByText('Wk 2')).toBeInTheDocument();
        expect(screen.queryByText('2 weeks behind')).not.toBeInTheDocument();
    });

    it('reveals the later phases, each under its own heading, on request', () => {
        renderTimeline();
        fireEvent.click(screen.getByRole('button', { name: /2 weeks ahead/ }));

        expect(screen.getByText('Build phase')).toBeInTheDocument();
        expect(screen.getByText('Peak phase')).toBeInTheDocument();
    });

    it('numbers every week by its place in the whole season', () => {
        renderTimeline();
        fireEvent.click(screen.getByRole('button', { name: /2 weeks ahead/ }));

        expect(screen.getByText('Wk 3')).toBeInTheDocument();
        expect(screen.getByText('Wk 4')).toBeInTheDocument();
        expect(screen.getByText('Wk 5')).toBeInTheDocument();
    });

    it('groups a self-scaled season by each pass through a phase, not by phase name', () => {
        renderTimeline({
            weeks: [
                week({
                    week_start: '2026-06-15',
                    phase: 'build',
                    type: 'current',
                }),
                week({
                    week_start: '2026-06-22',
                    phase: 'deload',
                    type: 'lookahead',
                }),
                week({
                    week_start: '2026-06-29',
                    phase: 'build',
                    type: 'lookahead',
                }),
            ],
        });
        fireEvent.click(screen.getByRole('button', { name: /2 weeks ahead/ }));

        expect(screen.getAllByText('Build phase')).toHaveLength(2);
        expect(screen.getByText('Deload phase')).toBeInTheDocument();
        // The second Build block is its own pass, not folded back into the first.
        expect(screen.getByText('Wk 3')).toBeInTheDocument();
    });
});
