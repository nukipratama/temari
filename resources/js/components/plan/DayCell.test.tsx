import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { PlanDay } from '@/lib/plan';

import { DayCellBody } from './DayCell';

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
        fall_off_tilt: null,
        goal_pace: null,
        advice_note: null,
        eased_from: null,
        pace_eased_from: null,
        credit_note: null,
        ran_hot: false,
        result_note: null,
        ran_pace_sec_per_km: null,
        actual_km: null,
        credited_km: null,
        activities: [],
        ...overrides,
    };
}

describe('DayCellBody', () => {
    it("colors the session-type icon by the day's effort: easy leaf, steady citrus, hard ember", () => {
        const { rerender, container } = render(
            <DayCellBody
                day={day({ session_type: 'easy' })}
                hasElapsed={false}
            />,
        );
        expect(container.querySelector('[data-icon="Feather"]')).toHaveClass(
            'text-leaf-ink',
        );

        rerender(
            <DayCellBody
                day={day({ session_type: 'tempo' })}
                hasElapsed={false}
            />,
        );
        expect(container.querySelector('[data-icon="Flame"]')).toHaveClass(
            'text-citrus-ink',
        );

        rerender(
            <DayCellBody
                day={day({ session_type: 'interval' })}
                hasElapsed={false}
            />,
        );
        expect(container.querySelector('[data-icon="Flame"]')).toHaveClass(
            'text-ember-ink',
        );

        rerender(
            <DayCellBody
                day={day({ session_type: 'rest' })}
                hasElapsed={false}
            />,
        );
        expect(container.querySelector('[data-icon="Bed"]')).toHaveClass(
            'text-foreground',
        );
    });

    it('shows the status glyph in the top-right corner', () => {
        const { container } = render(
            <DayCellBody
                day={day({ status: 'done', actual_km: 6 })}
                hasElapsed
            />,
        );

        const glyph = container.querySelector('[data-icon="Check"]');
        expect(glyph).not.toBeNull();
        expect(glyph).toHaveClass('absolute', 'top-1', 'right-1');
    });

    it("shows an elapsed day's actual km bold, and the planned figure below as 'of X', not clipped", () => {
        render(
            <DayCellBody
                day={day({ status: 'done', actual_km: 5, prescribed_km: 6 })}
                hasElapsed
            />,
        );

        expect(screen.getByText('5.0')).toBeInTheDocument();
        expect(screen.getByText('of 6.0')).toBeInTheDocument();
    });

    it('prefers the prescribed km over the (possibly redistributed) distance for the planned figure', () => {
        render(
            <DayCellBody
                day={day({
                    status: 'done',
                    actual_km: 5,
                    distance_km: 9,
                    prescribed_km: 6,
                })}
                hasElapsed
            />,
        );

        expect(screen.getByText('of 6.0')).toBeInTheDocument();
    });

    it('shows a still-ahead day as its planned km, with no glyph and no "of" line', () => {
        render(
            <DayCellBody day={day({ distance_km: 8 })} hasElapsed={false} />,
        );

        expect(screen.getByText('8.0')).toBeInTheDocument();
        expect(screen.queryByText(/^of /)).not.toBeInTheDocument();
    });

    it('shows a rest day as "rest", with no "of" line even once run anyway', () => {
        render(
            <DayCellBody
                day={day({
                    session_type: 'rest',
                    status: 'done',
                    ran_anyway: true,
                    actual_km: 4.2,
                })}
                hasElapsed
            />,
        );

        expect(screen.getByText('4.2')).toBeInTheDocument();
        expect(screen.queryByText(/^of /)).not.toBeInTheDocument();
    });

    it('prints the bare figure with no " km" suffix on both the ran and the planned line', () => {
        const { container, rerender } = render(
            <DayCellBody
                day={day({ status: 'done', actual_km: 10.1 })}
                hasElapsed
            />,
        );

        expect(container).not.toHaveTextContent(/km/);
        expect(screen.getByText('10.1')).toBeInTheDocument();

        rerender(
            <DayCellBody day={day({ distance_km: 21.1 })} hasElapsed={false} />,
        );

        expect(container).not.toHaveTextContent(/km/);
        expect(screen.getByText('21.1')).toBeInTheDocument();
        expect(container).not.toHaveTextContent(/of/);
    });

    it('draws the effort bar square and full-width along the bottom, never rounded', () => {
        const { container } = render(
            <DayCellBody
                day={day({ session_type: 'tempo' })}
                hasElapsed={false}
            />,
        );

        const bar = container.querySelector('.border-citrus');
        expect(bar).not.toBeNull();
        expect(bar).toHaveClass('absolute', 'inset-x-0', '-bottom-[3px]');
        expect(bar?.className).not.toMatch(/rounded/);
    });

    it('dashes the rest edge bar rather than coloring it', () => {
        const { container } = render(
            <DayCellBody
                day={day({ session_type: 'rest' })}
                hasElapsed={false}
            />,
        );

        expect(
            container.querySelector('.border-dashed.border-border'),
        ).not.toBeNull();
    });
});
