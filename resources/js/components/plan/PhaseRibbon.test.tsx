import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { SeasonSummaryWeek } from '@/lib/plan';

import { PHASE_COLORS } from '@/lib/chartTokens';

import PhaseRibbon from './PhaseRibbon';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-06-15',
        phase: 'base',
        zone: 'general',
        type: 'history',
        planned_km: 30,
        actual_km: null,
        sessions: 5,
        ...overrides,
    };
}

const RACE_SEASON: SeasonSummaryWeek[] = [
    week({
        week_start: '2026-05-01',
        zone: 'general',
        phase: 'build',
        type: 'history',
    }),
    week({
        week_start: '2026-05-08',
        zone: 'general',
        phase: 'deload',
        type: 'history',
    }),
    week({
        week_start: '2026-05-15',
        zone: 'block',
        phase: 'base',
        type: 'current',
    }),
    week({
        week_start: '2026-05-22',
        zone: 'block',
        phase: 'build',
        type: 'lookahead',
    }),
    week({
        week_start: '2026-05-29',
        zone: 'block',
        phase: 'peak',
        type: 'lookahead',
    }),
    week({
        week_start: '2026-06-05',
        zone: 'block',
        phase: 'taper',
        type: 'lookahead',
    }),
];

const SELF_SCALED_SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-05-01', zone: 'general', phase: 'build' }),
    week({
        week_start: '2026-05-08',
        zone: 'general',
        phase: 'deload',
        type: 'current',
    }),
];

describe('PhaseRibbon', () => {
    it('renders nothing for a season with no race block', () => {
        const { container } = render(
            <PhaseRibbon weeks={SELF_SCALED_SEASON} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('gives every general week the same neutral fill, ignoring its own phase', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        const generalCells = screen.getAllByRole('button', {
            name: 'maintain',
        });
        expect(generalCells).toHaveLength(2);
        for (const cell of generalCells) {
            expect(cell).toHaveClass('bg-muted');
            expect(cell).not.toHaveAttribute('style');
        }
    });

    it("colours each block week by its own phase, reading PHASE_COLORS' PlanPhaseKey pairing", () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        expect(
            screen.getByRole('button', { name: 'base, current week' }),
        ).toHaveStyle({
            backgroundColor: PHASE_COLORS.base,
        });
        expect(screen.getByRole('button', { name: 'peak' })).toHaveStyle({
            backgroundColor: PHASE_COLORS.peak,
        });
        expect(screen.getByRole('button', { name: 'taper' })).toHaveStyle({
            backgroundColor: PHASE_COLORS.taper,
        });
    });

    it('names the current week in its accessible name, on top of its phase', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        expect(
            screen.getByRole('button', { name: 'base, current week' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'base' }),
        ).not.toBeInTheDocument();
    });

    it('draws every week through the current one solid, and every week still ahead faded', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        const solid = [
            ...screen.getAllByRole('button', { name: 'maintain' }),
            screen.getByRole('button', { name: 'base, current week' }),
        ];
        for (const cell of solid) {
            expect(cell).not.toHaveClass('opacity-40');
        }

        for (const name of ['build', 'peak', 'taper']) {
            expect(screen.getByRole('button', { name })).toHaveClass(
                'opacity-40',
            );
        }
    });

    it('carries no digit in any accessible name or visible text', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        for (const button of screen.getAllByRole('button')) {
            expect(button.getAttribute('aria-label') ?? '').not.toMatch(/\d/);
        }
    });

    it('reveals the phase name on hover and clears it on mouse leave', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        const cell = screen.getByRole('button', { name: 'peak' });
        fireEvent.mouseEnter(cell);
        expect(screen.getByText('peak')).toBeInTheDocument();

        fireEvent.mouseLeave(cell);
        expect(screen.queryByText('peak')).not.toBeInTheDocument();
    });

    it('reveals the phase name on keyboard focus and clears it on blur', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        const cell = screen.getByRole('button', { name: 'taper' });
        fireEvent.focus(cell);
        expect(screen.getByText('taper')).toBeInTheDocument();

        fireEvent.blur(cell);
        expect(screen.queryByText('taper')).not.toBeInTheDocument();
    });

    it('reveals "current week" on tap for the current cell', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        const current = screen.getByRole('button', {
            name: 'base, current week',
        });
        fireEvent.click(current);
        expect(screen.getByText('base, current week')).toBeInTheDocument();
    });

    it('reveals the phase name on tap, and a tap elsewhere moves it rather than toggling it off', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        const build = screen.getByRole('button', { name: 'build' });
        fireEvent.click(build);
        expect(screen.getByText('build')).toBeInTheDocument();

        // A tap also focuses the button on most browsers, which would fire
        // onFocus right before onClick — the label must not flicker off from
        // that race.
        fireEvent.click(build);
        expect(screen.getByText('build')).toBeInTheDocument();

        const peak = screen.getByRole('button', { name: 'peak' });
        fireEvent.click(peak);
        expect(screen.queryByText('build')).not.toBeInTheDocument();
        expect(screen.getByText('peak')).toBeInTheDocument();
    });

    it('reveals the general band label on hover, not a general week’s own phase', () => {
        render(<PhaseRibbon weeks={RACE_SEASON} />);

        const [firstGeneralCell] = screen.getAllByRole('button', {
            name: 'maintain',
        });
        fireEvent.mouseEnter(firstGeneralCell);

        expect(screen.getByText('maintain')).toBeInTheDocument();
        expect(screen.queryByText('build')).not.toBeInTheDocument();
        expect(screen.queryByText('deload')).not.toBeInTheDocument();
    });
});
