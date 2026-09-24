import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { PlanDay, PlanWeek, SeasonSummaryWeek } from '@/lib/plan';

import SeasonTimeline from './SeasonTimeline';

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

function day(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: 1,
        date: '2026-06-15',
        phase: 'base',
        session_type: 'easy',
        segments: [],
        distance_km: 6,
        asked_km: 6,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        prescription_reason: null,
        clamp: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        hot_note: null,
        ran_pace_sec_per_km: null,
        actual_km: null,
        credited_km: null,
        activities: [],
        flagged: false,
        ...overrides,
    };
}

/** An unloaded week, a loaded week behind, this week, a loaded week ahead. */
const SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-06-01' }),
    week({ week_start: '2026-06-08' }),
    week({ week_start: '2026-06-15', type: 'current' }),
    week({ week_start: '2026-06-22', phase: 'build', type: 'lookahead' }),
];

const DETAIL: Record<string, PlanWeek> = {
    '2026-06-08': {
        week_start: '2026-06-08',
        phase: 'base',
        type: 'history',
        days: [
            day({ id: 11, date: '2026-06-08', status: 'done', actual_km: 6 }),
            day({ id: 12, date: '2026-06-09', status: 'missed' }),
        ],
    },
    '2026-06-15': {
        week_start: '2026-06-15',
        phase: 'base',
        type: 'current',
        days: [
            day({ id: 21, date: '2026-06-16' }),
            day({ id: 22, date: '2026-06-17' }),
        ],
    },
    '2026-06-22': {
        week_start: '2026-06-22',
        phase: 'build',
        type: 'lookahead',
        days: [
            day({
                id: 31,
                date: '2026-06-22',
                session_type: 'rest',
                distance_km: 0,
            }),
            day({ id: 32, date: '2026-06-23', session_type: 'tempo' }),
        ],
    },
};

const FOCUS = { headline: 'Holding the line.', detail: 'Volume stays put.' };

function renderTimeline(
    overrides: Partial<Parameters<typeof SeasonTimeline>[0]> = {},
) {
    return render(
        <SeasonTimeline
            weeks={SEASON}
            detailByWeekStart={DETAIL}
            today="2026-06-17"
            weekFocus={FOCUS}
            dayNarration={{}}
            onMove={vi.fn()}
            onSkip={vi.fn()}
            {...overrides}
        />,
    );
}

const shownWeek = () => screen.getByRole('region', { name: /^week/ });
const selectedTab = () =>
    screen
        .getAllByRole('tab')
        .find((tab) => tab.getAttribute('aria-selected') === 'true');
const rowButton = (label: string) =>
    within(screen.getByRole('list', { name: 'season weeks' }))
        .getAllByRole('button')
        .find((button) => button.textContent?.includes(label))!;

describe('SeasonTimeline', () => {
    let scrollIntoView: ReturnType<
        typeof vi.fn<(arg?: boolean | ScrollIntoViewOptions) => void>
    >;

    beforeEach(() => {
        scrollIntoView =
            vi.fn<(arg?: boolean | ScrollIntoViewOptions) => void>();
        Element.prototype.scrollIntoView = scrollIntoView;
    });

    it('renders nothing before a current week exists', () => {
        const { container } = renderTimeline({
            weeks: [week({ type: 'history' })],
        });

        expect(container).toBeEmptyDOMElement();
    });

    it('opens on this week, today selected, above the list of every week', () => {
        renderTimeline();

        expect(shownWeek()).toHaveAccessibleName('week 3');
        expect(selectedTab()).toHaveAccessibleName(/today/);
        expect(screen.getByText('Holding the line.')).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('list', { name: 'season weeks' }),
            ).getAllByRole('listitem'),
        ).toHaveLength(4);
    });

    it('draws no rail and no behind/ahead clusters', () => {
        renderTimeline();

        expect(screen.queryByText(/weeks? (behind|ahead)/)).toBeNull();
        expect(screen.queryByText(/phase$/)).toBeNull();
    });

    it('swaps a week ahead into the strip and panel, opening on its first session to run', () => {
        renderTimeline();
        fireEvent.click(rowButton('Week 4'));

        expect(shownWeek()).toHaveAccessibleName('week 4');
        expect(selectedTab()).toHaveAccessibleName('Tue, 6.0 km, tempo');
        expect(screen.getByRole('tabpanel')).toHaveTextContent('tempo');
        expect(screen.queryByText('Holding the line.')).not.toBeInTheDocument();
        expect(scrollIntoView).toHaveBeenCalledWith({ block: 'start' });
    });

    it('swaps a week behind in, opening on its first day', () => {
        renderTimeline();
        fireEvent.click(rowButton('Week 2'));

        expect(shownWeek()).toHaveAccessibleName('week 2');
        expect(selectedTab()).toHaveAccessibleName('Mon, 6.0 km, done');
    });

    it('returns to this week from the pill', () => {
        renderTimeline();
        fireEvent.click(rowButton('Week 2'));
        fireEvent.click(
            screen.getByRole('button', { name: /back to this week/i }),
        );

        expect(shownWeek()).toHaveAccessibleName('week 3');
        expect(selectedTab()).toHaveAccessibleName(/today/);
        expect(
            screen.queryByRole('button', { name: /back to this week/i }),
        ).not.toBeInTheDocument();
    });

    it('offers no swap for a week outside the loaded window', () => {
        renderTimeline();
        const rows = within(
            screen.getByRole('list', { name: 'season weeks' }),
        ).getAllByRole('listitem');

        expect(within(rows[0]).queryByRole('button')).not.toBeInTheDocument();
    });

    it('opens on the week and day /plan?day= asked for, even outside this week', () => {
        renderTimeline({ focusDay: '2026-06-09' });

        expect(shownWeek()).toHaveAccessibleName('week 2');
        expect(selectedTab()).toHaveAccessibleName('Tue, 6.0 km, missed');
        expect(scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
    });

    it('selects the asked-for day in this week too', () => {
        renderTimeline({ focusDay: '2026-06-16' });

        expect(shownWeek()).toHaveAccessibleName('week 3');
        expect(selectedTab()).toHaveAccessibleName('Tue, 6.0 km, easy');
    });

    it('does not jump back to the asked-for day after moving on', () => {
        renderTimeline({ focusDay: '2026-06-09' });
        fireEvent.click(
            screen.getByRole('button', { name: /back to this week/i }),
        );
        fireEvent.click(rowButton('Week 2'));

        expect(selectedTab()).toHaveAccessibleName('Mon, 6.0 km, done');
    });
});
