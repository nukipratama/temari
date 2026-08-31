import type { PlanDay } from '@/lib/plan';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

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
                pace_sec_per_km: 300,
            },
        ],
        distance_km: 8,
        pinned: false,
        skipped: false,
        status: 'planned',
        compliance_score: null,
        ran_anyway: false,
        clamp_note: null,
        actual_km: null,
        activity: null,
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
        expect(screen.getByText('Tempo')).toBeInTheDocument();
        expect(screen.getByText('8 km · 5:00/km')).toBeInTheDocument();
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

        expect(screen.getByText('Partial · 60%')).toBeInTheDocument();
    });

    it('reads an excused upcoming day as skipped before the scorer has run', () => {
        renderRow({ day: day({ skipped: true }) });

        expect(screen.getByText('Skipped')).toBeInTheDocument();
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
                activity: { id: 42, seconds: 2720 },
            }),
        });
        expand();

        const link = screen.getByRole('link', { name: /view activity/i });
        expect(link).toHaveAttribute('href', '/activities/42');
        expect(link).toHaveTextContent('8.1 km · 45:20');
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
                actual_km: 5,
                activity: { id: 7, seconds: 1800 },
            }),
        });

        expect(
            screen.getByText('Ran anyway · 5 km · 30:00'),
        ).toBeInTheDocument();
    });

    it('surfaces the readiness clamp note when today was scaled back', () => {
        renderRow({
            day: day({ date: TODAY, clamp_note: 'Eased off, you slept badly.' }),
        });
        expand();

        expect(
            screen.getByText('Eased off, you slept badly.'),
        ).toBeInTheDocument();
    });
});
