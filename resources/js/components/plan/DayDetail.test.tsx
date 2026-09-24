import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PlanDay } from '@/lib/plan';
import type { AnalysisPayload } from '@/types/inertia';

import DayDetail, {
    DayHeadline,
    hasDayDetail,
    showsNarration,
} from './DayDetail';

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
        ...overrides,
    };
}

const REST = day({
    id: 2,
    date: '2026-06-19',
    session_type: 'rest',
    segments: [],
    distance_km: 0,
});

function narration(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'solid tempo.',
        type: 'plan_day_voice',
        ...overrides,
    } as AnalysisPayload;
}

describe('showsNarration', () => {
    it('shows a finished read and a failed one, never a pending or empty one', () => {
        expect(showsNarration(narration())).toBe(true);
        expect(showsNarration(narration({ status: 'failed' }))).toBe(true);
        expect(showsNarration(narration({ status: 'pending' }))).toBe(false);
        expect(showsNarration(narration({ content: null }))).toBe(false);
        expect(showsNarration(null)).toBe(false);
    });
});

describe('hasDayDetail', () => {
    it('has detail for a sized session still ahead', () => {
        expect(hasDayDetail(day(), [day(), REST], TODAY, null)).toBe(true);
    });

    it('has none for a plain rest day', () => {
        expect(hasDayDetail(REST, [day(), REST], TODAY, null)).toBe(false);
    });

    it('has detail for a rest day once a read is in', () => {
        expect(hasDayDetail(REST, [REST], TODAY, narration())).toBe(true);
    });

    it('has detail for a clamped day even with no segments', () => {
        expect(
            hasDayDetail(
                day({
                    date: TODAY,
                    segments: [],
                    clamp: {
                        label: 'eased for today',
                        session_type: 'easy',
                        distance_km: 6,
                        pace_sec_per_km: null,
                        note: 'legs need it.',
                    },
                }),
                [],
                TODAY,
                null,
            ),
        ).toBe(true);
    });
});

describe('DayHeadline', () => {
    it('states the session, its km and pace', () => {
        render(<DayHeadline day={day()} />);

        expect(screen.getByText('tempo')).toBeInTheDocument();
        expect(screen.getByText('8 km · 5:00/km')).toBeInTheDocument();
    });

    it('pairs the ask with what was run on a judged day, with its verdict', () => {
        render(
            <DayHeadline
                day={day({
                    status: 'done',
                    compliance_score: 95,
                    prescribed_km: 8,
                    actual_km: 7.6,
                })}
            />,
        );

        expect(screen.getByText(/asked 8 km/)).toBeInTheDocument();
        expect(screen.getByText(/ran 7.6 km/)).toBeInTheDocument();
        expect(screen.getByText('done · 95%')).toBeInTheDocument();
    });

    it('draws the zone strip only when asked', () => {
        const { container, rerender } = render(<DayHeadline day={day()} />);
        const bare = container.innerHTML;

        rerender(<DayHeadline day={day()} withMiniBar />);

        expect(container.innerHTML).not.toBe(bare);
    });
});

describe('DayDetail', () => {
    function renderDetail(
        overrides: Partial<Parameters<typeof DayDetail>[0]> = {},
    ) {
        const onMove = vi.fn();
        const onSkip = vi.fn();
        render(
            <DayDetail
                day={day()}
                weekDays={[day(), REST]}
                today={TODAY}
                narration={null}
                onMove={onMove}
                onSkip={onSkip}
                {...overrides}
            />,
        );
        return { onMove, onSkip };
    }

    it('says what a quality session is for', () => {
        renderDetail();

        expect(screen.getByText('the point')).toBeInTheDocument();
    });

    it("carries Temari's read when one is showable", () => {
        renderDetail({ narration: narration() });

        expect(screen.getByText("Temari's read")).toBeInTheDocument();
    });

    it('skips and moves through the caller', () => {
        const { onMove, onSkip } = renderDetail();

        fireEvent.click(
            screen.getByRole('button', { name: /skip this session/i }),
        );
        expect(onSkip).toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', { name: /move this session/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Fri' }));
        expect(onMove).toHaveBeenCalledWith('2026-06-19');
        expect(
            screen.getByRole('button', { name: /move this session/i }),
        ).toBeInTheDocument();
    });

    it('offers neither action on a day already passed', () => {
        renderDetail({ day: day({ date: '2026-06-16' }) });

        expect(
            screen.queryByRole('button', { name: /skip this session/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /move this session/i }),
        ).not.toBeInTheDocument();
    });
});
