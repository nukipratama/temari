import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanDay } from '@/lib/plan';

import WeekDayRow from './WeekDayRow';

const TODAY = '2026-06-17';

function day(overrides: Partial<PlanDay> = {}): PlanDay {
    return {
        id: 1,
        date: '2026-06-18',
        phase: 'base',
        session_type: 'tempo',
        segments: [
            {
                key: 'main',
                minutes: 30,
                zone: 'Z4',
                pace_label: 'threshold',
                km: 5.2,
                pace_sec_per_km: 300,
            },
        ],
        distance_km: 8,
        asked_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        prescribed_km: null,
        clamp: null,
        actual_km: null,
        activities: [],
        flagged: false,
        ...overrides,
    };
}

/** Thursday's tempo, with Fri/Sat rest days it could move onto. */
const WEEK: PlanDay[] = [
    day({ id: 1, date: '2026-06-18' }),
    day({
        id: 2,
        date: '2026-06-19',
        session_type: 'rest',
        segments: [],
        distance_km: 0,
    }),
    day({
        id: 3,
        date: '2026-06-20',
        session_type: 'rest',
        segments: [],
        distance_km: 0,
    }),
];

function renderRow(overrides: Partial<Parameters<typeof WeekDayRow>[0]> = {}) {
    const props = {
        day: WEEK[0],
        weekDays: WEEK,
        today: TODAY,
        narration: null,
        onMove: vi.fn(),
        onSkip: vi.fn(),
        ...overrides,
    };
    render(<WeekDayRow {...props} />);
    return props;
}

function expand() {
    fireEvent.click(screen.getByRole('button', { name: /tempo/i }));
}

describe('WeekDayRow', () => {
    it('summarises the day without expanding it', () => {
        renderRow();

        expect(screen.getByText('Thu')).toBeInTheDocument();
        expect(screen.getByText('tempo')).toBeInTheDocument();
        expect(screen.getByText('8 km · 5:00/km')).toBeInTheDocument();
    });

    it("says why the ask moved when the week's live redistribution shrank it", () => {
        renderRow({ day: day({ distance_km: 3, asked_km: 8 }) });

        expect(
            screen.getByText('asked for 8 km · adjusted for the week'),
        ).toBeInTheDocument();
    });

    it('stays quiet when the redistributed figure matches what was asked', () => {
        renderRow({ day: day({ distance_km: 8, asked_km: 8 }) });

        expect(
            screen.queryByText(/adjusted for the week/),
        ).not.toBeInTheDocument();
    });

    it('stays quiet once the day is graded, even if distance and ask differ', () => {
        renderRow({
            day: day({
                distance_km: 3,
                asked_km: 8,
                status: 'done',
                prescribed_km: 8,
                actual_km: 8,
            }),
        });

        expect(
            screen.queryByText(/adjusted for the week/),
        ).not.toBeInTheDocument();
    });

    it('starts closed, as the prototype does', () => {
        renderRow();

        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
    });

    it('labels a scored day with its verdict and score', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'partial',
                compliance_score: 60,
            }),
        });

        expect(screen.getByText('partial · 60%')).toBeInTheDocument();
    });

    it('reads an excused upcoming day as skipped before the scorer has run', () => {
        renderRow({ day: day({ skipped: true }) });

        expect(screen.getByText('skipped')).toBeInTheDocument();
    });

    it('shows nothing but the plan on a day still ahead', () => {
        renderRow();

        expect(screen.queryByText('Done')).not.toBeInTheDocument();
    });

    it('offers move and skip on a day still ahead', () => {
        renderRow();
        expand();

        expect(
            screen.getByRole('button', { name: /move this session/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /skip this session/i }),
        ).toBeInTheDocument();
    });

    it('offers neither on a day that has already passed', () => {
        renderRow({ day: day({ date: '2026-06-15', status: 'done' }) });
        expand();

        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /skip this session/i }),
        ).not.toBeInTheDocument();
    });

    it('offers neither on a rest day', () => {
        renderRow({
            day: WEEK[1],
        });
        fireEvent.click(screen.getByRole('button', { name: /rest/i }));

        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
    });

    it('does not offer skip twice on an already-excused day', () => {
        renderRow({ day: day({ skipped: true }) });
        expand();

        expect(
            screen.queryByRole('button', { name: /skip this session/i }),
        ).not.toBeInTheDocument();
    });

    it('skips through to the caller', () => {
        const { onSkip } = renderRow();
        expand();
        fireEvent.click(
            screen.getByRole('button', { name: /skip this session/i }),
        );

        expect(onSkip).toHaveBeenCalledOnce();
    });

    it('offers a weekday picker whose only enabled targets are later rest days', () => {
        renderRow();
        expand();
        fireEvent.click(
            screen.getByRole('button', { name: /move this session/i }),
        );

        expect(screen.getByRole('button', { name: 'Fri' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Sat' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Thu' })).toBeDisabled();
    });

    it('moves onto the picked day and closes the picker', () => {
        const { onMove } = renderRow();
        expand();
        fireEvent.click(
            screen.getByRole('button', { name: /move this session/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Fri' }));

        expect(onMove).toHaveBeenCalledWith('2026-06-19');
        expect(
            screen.getByRole('button', { name: /move this session/i }),
        ).toBeInTheDocument();
    });

    it('hides move when the week has no rest day left to move onto', () => {
        const noTargets = [day({ id: 1, date: '2026-06-18' })];
        renderRow({ day: noTargets[0], weekDays: noTargets });
        expand();

        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
    });

    it('links to what was actually run', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'done',
                actual_km: 8.1,
                activities: [{ id: 42, km: 8.1, seconds: 2720 }],
            }),
        });
        expand();

        const link = screen.getByRole('link', { name: /view activity/i });
        expect(link).toHaveAttribute('href', '/activities/42');
        expect(link).toHaveTextContent('8.1 km · 45:20');
    });

    /**
     * Reported from prod: a 5 km and a 7 km session on one day rendered as a
     * single "12 km · 55:00" — the day's summed distance beside the longer
     * run's clock, an impossible 4:35/km that the athlete never ran.
     */
    it('gives a two-session day one line per run, each with its own time', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                status: 'done',
                actual_km: 12,
                activities: [
                    { id: 11, km: 5, seconds: 1380 },
                    { id: 12, km: 7, seconds: 3300 },
                ],
            }),
        });
        expand();

        const links = screen.getAllByRole('link', { name: /view activity/i });
        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', '/activities/11');
        expect(links[0]).toHaveTextContent('5 km · 23:00');
        expect(links[1]).toHaveAttribute('href', '/activities/12');
        expect(links[1]).toHaveTextContent('7 km · 55:00');
        // The summed distance must never appear beside one run's duration.
        expect(screen.queryByText(/12 km · 55:00/)).not.toBeInTheDocument();
    });

    it('shows no activity link on a day with nothing logged', () => {
        renderRow({ day: day({ date: '2026-06-15', activities: [] }) });
        expand();

        expect(
            screen.queryByRole('link', { name: /view activity/i }),
        ).not.toBeInTheDocument();
    });

    it('sums every run when a rest day was run more than once', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                session_type: 'rest',
                segments: [],
                distance_km: 0,
                status: 'done',
                ran_anyway: true,
                prescribed_km: null,
                actual_km: 12,
                activities: [
                    { id: 11, km: 5, seconds: 1380 },
                    { id: 12, km: 7, seconds: 3300 },
                ],
            }),
        });

        expect(
            screen.getByText('Ran anyway · 12 km · 1:18:00'),
        ).toBeInTheDocument();
    });

    it('calls out a rest day that was run anyway', () => {
        renderRow({
            day: day({
                date: '2026-06-15',
                session_type: 'rest',
                segments: [],
                distance_km: 0,
                status: 'done',
                ran_anyway: true,
                prescribed_km: null,
                actual_km: 5,
                activities: [{ id: 7, km: 5, seconds: 1800 }],
            }),
        });

        expect(
            screen.getByText('Ran anyway · 5 km · 30:00'),
        ).toBeInTheDocument();
    });

    /**
     * The clamp is advisory: it eases today, it does not replace the plan. The
     * card must still lead with what the plan asked for, because that is what
     * the narration above it describes and what compliance grades against.
     */
    it('shows the readiness step-down beside the day, not instead of it', () => {
        renderRow({
            day: day({
                date: TODAY,
                distance_km: 9.1,
                clamp: {
                    session_type: 'easy',
                    distance_km: 5.9,
                    pace_sec_per_km: 450,
                    note: 'Eased off, you slept badly.',
                    label: 'eased today',
                },
            }),
        });
        expand();

        expect(screen.getByText('9.1 km · 5:00/km')).toBeInTheDocument();
        expect(screen.getByText('eased today')).toBeInTheDocument();
        expect(screen.getByText('easy · 5.9 km · 7:30/km')).toBeInTheDocument();
        expect(
            screen.getByText('Eased off, you slept badly.'),
        ).toBeInTheDocument();
    });

    /** The server decides what the step-down is for; the row must not hardcode
     *  a label that contradicts the note beside it. */
    it('renders the server label rather than a fixed one', () => {
        renderRow({
            day: day({
                date: TODAY,
                distance_km: 9.1,
                clamp: {
                    session_type: 'easy',
                    distance_km: 3.6,
                    pace_sec_per_km: 450,
                    note: "You've already run today, so anything else stays easy.",
                    label: 'anything else today',
                },
            }),
        });
        expand();

        expect(screen.getByText('anything else today')).toBeInTheDocument();
        expect(screen.queryByText('eased today')).not.toBeInTheDocument();
    });

    it('shows no step-down on a day the clamp did not touch', () => {
        renderRow();
        expand();

        expect(screen.queryByText('eased today')).not.toBeInTheDocument();
    });

    it('states both recorded facts on a day the plan has judged: what it asked for, and what was run', () => {
        renderRow({
            day: day({ prescribed_km: 6, actual_km: 5, distance_km: 8 }),
        });

        expect(screen.getByText(/6 km asked · 5 km run/)).toBeInTheDocument();
        expect(screen.queryByText(/8 km/)).not.toBeInTheDocument();
    });

    it('keeps the two numbers straight on a day that went long', () => {
        renderRow({
            day: day({ prescribed_km: 9, actual_km: 12, distance_km: 8 }),
        });

        expect(screen.getByText(/9 km asked · 12 km run/)).toBeInTheDocument();
    });

    it('shows the ask alone on a day that has not been judged yet', () => {
        renderRow({ day: day({ prescribed_km: null, distance_km: 8 }) });

        expect(screen.getByText(/8 km/)).toBeInTheDocument();
    });

    it('offers one icon-only flag control without expanding the day', () => {
        renderRow();

        const flag = screen.getByRole('button', { name: 'flag this day' });

        expect(flag).toHaveTextContent('');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('keeps the flag out of the trigger it sits beside', () => {
        renderRow();

        const trigger = screen.getByRole('button', { name: /tempo/i });
        const flag = screen.getByRole('button', { name: 'flag this day' });

        expect(trigger).not.toContainElement(flag);
        expect(flag.parentElement).toBe(trigger.parentElement);
    });

    it('draws an inert flagged icon on a day already flagged', () => {
        renderRow({ day: day({ flagged: true }) });

        expect(screen.getByLabelText('flagged')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'flag this day' }),
        ).toBeNull();
    });
});
