import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { PlanDay, SeasonSummaryWeek } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import WeekView from './WeekView';

const TODAY = '2026-06-17';

function day(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: 1,
        date: '2026-06-15',
        phase: 'base',
        session_type: 'easy',
        segments: [
            {
                key: 'main',
                minutes: 30,
                zone: 'Z2',
                pace_label: 'easy',
                km: 5,
                pace_sec_per_km: 360,
            },
        ],
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

function narration(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'held it easy the whole way.',
        type: 'plan_day_voice',
        is_zone_dependent: false,
        subject_type: 'plan_day_voice_user_day',
        subject_id: 1,
        discriminator: '2026-06-15',
        ...overrides,
    } as AnalysisPayload;
}

/** Mon done, Tue missed, Wed today (easy), Thu tempo, Fri rest. */
const WEEK: PlanDay[] = [
    day({
        id: 1,
        date: '2026-06-15',
        status: 'done',
        compliance_score: 100,
        actual_km: 6,
        prescribed_km: 6,
        activities: [{ id: 91, km: 6, seconds: 2160 }],
    }),
    day({
        id: 2,
        date: '2026-06-16',
        status: 'missed',
        compliance_score: 0,
        prescribed_km: 7,
    }),
    day({ id: 3, date: '2026-06-17' }),
    day({ id: 4, date: '2026-06-18', session_type: 'tempo', distance_km: 8 }),
    day({
        id: 5,
        date: '2026-06-19',
        session_type: 'rest',
        segments: [],
        distance_km: 0,
    }),
];

function summaryWeek(
    overrides: Partial<SeasonSummaryWeek> = {},
): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        zone: 'block',
        type: 'current',
        planned_km: 30.4,
        actual_km: 12,
        sessions: 5,
        ...overrides,
    };
}

function renderWeek(overrides: Partial<Parameters<typeof WeekView>[0]> = {}) {
    const onMove = vi.fn();
    const onSkip = vi.fn();
    render(
        <WeekView
            week={summaryWeek()}
            weekNumber={3}
            days={WEEK}
            today={TODAY}
            focus={null}
            dayNarration={{}}
            onMove={onMove}
            onSkip={onSkip}
            {...overrides}
        />,
    );
    return { onMove, onSkip };
}

const selectedTab = () =>
    screen
        .getAllByRole('tab')
        .find((tab) => tab.getAttribute('aria-selected') === 'true');

describe('WeekView', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('heads the week with its number, dates, target, sessions and adherence', () => {
        renderWeek();

        expect(screen.getByText('Week 3')).toBeInTheDocument();
        expect(screen.getByText('jun 15–21')).toBeInTheDocument();
        expect(
            screen.getByText('30 km target · 5 sessions · 50%'),
        ).toBeInTheDocument();
    });

    it('heads an eased week with the eased target and the original beside it', () => {
        renderWeek({
            week: summaryWeek({ planned_km: 24.6, eased_from_km: 26.9 }),
        });

        expect(screen.getByText('27')).toBeInTheDocument();
        expect(screen.getByText('25 km target')).toBeInTheDocument();
    });

    it('marks a deload week and the race week in words', () => {
        renderWeek({
            week: summaryWeek({ phase: 'deload' }),
            raceDate: '2026-06-21',
        });

        expect(screen.getByText('deload week')).toBeInTheDocument();
        expect(screen.getByText('race week')).toBeInTheDocument();
    });

    it('marks a plain week not at all', () => {
        renderWeek({ raceDate: '2026-07-05' });

        expect(screen.queryByText('deload week')).not.toBeInTheDocument();
        expect(screen.queryByText('race week')).not.toBeInTheDocument();
    });

    it('offers the way back only when given one', () => {
        const onBack = vi.fn();
        renderWeek({ week: summaryWeek({ type: 'history' }), onBack });
        fireEvent.click(
            screen.getByRole('button', { name: /back to this week/i }),
        );

        expect(onBack).toHaveBeenCalled();
    });

    it('draws no way back on the current week', () => {
        renderWeek();

        expect(
            screen.queryByRole('button', { name: /back to this week/i }),
        ).not.toBeInTheDocument();
    });

    it('says how another week went without calling it so far', () => {
        renderWeek({ week: summaryWeek({ type: 'history' }) });

        expect(screen.getByText('1 done · 1 missed')).toBeInTheDocument();
    });

    it('opens on today', () => {
        renderWeek();

        expect(selectedTab()).toHaveAttribute(
            'aria-label',
            'Wed, 6.0 km, easy, today',
        );
        expect(screen.getByRole('tabpanel')).toHaveTextContent(
            'Wed · jun 17 · today',
        );
    });

    it('opens on the first session still to run when the week holds no today', () => {
        renderWeek({ today: '2026-06-10' });

        expect(selectedTab()).toHaveAttribute(
            'aria-label',
            'Wed, 6.0 km, easy',
        );
    });

    it('falls back to the first day when nothing is left to run', () => {
        renderWeek({ days: [WEEK[0], WEEK[4]], today: '2026-06-22' });

        expect(selectedTab()).toHaveAttribute(
            'aria-label',
            'Mon, 6.0 km, done',
        );
    });

    it('passes over days already run or missed and excused ones', () => {
        renderWeek({
            days: [WEEK[0], WEEK[1], { ...WEEK[2], skipped: true }, WEEK[3]],
            today: '2026-06-10',
        });

        expect(selectedTab()).toHaveAttribute(
            'aria-label',
            'Thu, 8.0 km, tempo',
        );
    });

    it('swaps the panel to the tile picked', () => {
        renderWeek();
        fireEvent.click(screen.getByRole('tab', { name: /^Thu/ }));

        const panel = screen.getByRole('tabpanel');
        expect(selectedTab()).toHaveAttribute(
            'aria-label',
            'Thu, 8.0 km, tempo',
        );
        expect(panel).toHaveTextContent('Thu · jun 18');
        expect(panel).toHaveTextContent('tempo');
        expect(panel).toHaveAttribute('aria-labelledby', selectedTab()!.id);
        expect(selectedTab()).toHaveAttribute('aria-controls', panel.id);
    });

    it('opens on the day /plan?day= asked for and scrolls the panel into view', () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;

        renderWeek({ focusDay: '2026-06-16' });

        expect(selectedTab()).toHaveAttribute(
            'aria-label',
            'Tue, 7.0 km, missed',
        );
        expect(scrollIntoView).toHaveBeenCalledWith({ block: 'center' });
    });

    it('keeps today and stays put when the day asked for is in another week', () => {
        const scrollIntoView = vi.fn();
        Element.prototype.scrollIntoView = scrollIntoView;

        renderWeek({ focusDay: '2026-06-02' });

        expect(selectedTab()).toHaveAttribute(
            'aria-label',
            'Wed, 6.0 km, easy, today',
        );
        expect(scrollIntoView).not.toHaveBeenCalled();
    });

    it('says how the week has gone so far', () => {
        renderWeek();

        expect(
            screen.getByText('1 done · 1 missed so far'),
        ).toBeInTheDocument();
    });

    it('says nothing about progress before any day is graded', () => {
        renderWeek({ days: [WEEK[2], WEEK[3]] });

        expect(screen.queryByText(/so far/)).not.toBeInTheDocument();
    });

    it('shows the adaptation note in full above the strip', () => {
        renderWeek({
            focus: {
                headline: 'Holding the line.',
                detail: 'Volume stays put this week.',
            },
        });

        const note = screen.getByText('Holding the line.');
        expect(screen.getByText('Volume stays put this week.')).toBeVisible();
        expect(
            note.compareDocumentPosition(screen.getByRole('tablist')) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it("shows Temari's read on a credited day once its narration is in", () => {
        renderWeek({
            focusDay: '2026-06-15',
            dayNarration: { '2026-06-15': narration() },
        });

        expect(screen.getByText("Temari's read")).toBeInTheDocument();
        expect(
            screen.getByText('held it easy the whole way.'),
        ).toBeInTheDocument();
    });

    it('draws no read while the day narration is pending', () => {
        renderWeek({
            focusDay: '2026-06-15',
            dayNarration: {
                '2026-06-15': narration({ status: 'pending', content: null }),
            },
        });

        expect(screen.queryByText("Temari's read")).not.toBeInTheDocument();
    });

    it("shows the run's asked and ran result and its activity link", () => {
        renderWeek({ focusDay: '2026-06-15' });

        const panel = screen.getByRole('tabpanel');
        expect(panel).toHaveTextContent(/asked 6 km/);
        expect(panel).toHaveTextContent(/ran 6 km/);
        expect(
            within(panel).getByRole('link', { name: /view activity/i }),
        ).toHaveAttribute('href', '/activities/91');
    });

    it('skips the selected session through the caller', () => {
        const { onSkip } = renderWeek();
        fireEvent.click(screen.getByRole('tab', { name: /^Thu/ }));
        fireEvent.click(
            screen.getByRole('button', { name: /skip this session/i }),
        );

        expect(onSkip).toHaveBeenCalledWith(WEEK[3]);
    });

    it('moves the selected session onto a later rest day in the same week', () => {
        const { onMove } = renderWeek();
        fireEvent.click(screen.getByRole('tab', { name: /^Thu/ }));
        fireEvent.click(
            screen.getByRole('button', { name: /move this session/i }),
        );

        expect(screen.getByRole('button', { name: 'Wed' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Fri' }));

        expect(onMove).toHaveBeenCalledWith(WEEK[3], '2026-06-19');
    });

    it('offers the flag on the selected day', () => {
        renderWeek();

        expect(
            within(screen.getByRole('tabpanel')).getByRole('button', {
                name: /flag this day/i,
            }),
        ).toBeInTheDocument();
    });

    it('draws neither strip nor panel for a week with no days', () => {
        renderWeek({ days: [] });

        expect(screen.queryByRole('tablist')).not.toBeInTheDocument();
        expect(screen.queryByRole('tabpanel')).not.toBeInTheDocument();
    });
});
