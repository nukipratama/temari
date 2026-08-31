import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SeasonPhaseBar, { type SeasonSummaryWeek } from './SeasonPhaseBar';

function week(overrides: Partial<SeasonSummaryWeek> = {}): SeasonSummaryWeek {
    return {
        week_start: '2026-08-10',
        phase: 'base',
        type: 'history',
        planned_km: 30,
        actual_km: null,
        ...overrides,
    };
}

describe('SeasonPhaseBar', () => {
    it('renders nothing for an empty season', () => {
        const { container } = render(<SeasonPhaseBar weeks={[]} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('merges consecutive same-phase weeks into one legend entry', () => {
        render(
            <SeasonPhaseBar
                weeks={[
                    week({
                        week_start: '2026-08-10',
                        phase: 'base',
                        planned_km: 30,
                    }),
                    week({
                        week_start: '2026-08-17',
                        phase: 'base',
                        planned_km: 32,
                    }),
                    week({
                        week_start: '2026-08-24',
                        phase: 'build',
                        planned_km: 35,
                        type: 'current',
                    }),
                ]}
            />,
        );

        expect(screen.getByText('Base · 62 km')).toBeInTheDocument(); // 30 + 32 merged
        expect(screen.getByText('Build · 35 km')).toBeInTheDocument();
    });

    it('renders a repeating Build/Deload cycle honestly, without forcing a Base/Peak/Taper shape', () => {
        render(
            <SeasonPhaseBar
                weeks={[
                    week({
                        week_start: '2026-08-10',
                        phase: 'build',
                        planned_km: 30,
                        type: 'current',
                    }),
                    week({
                        week_start: '2026-08-17',
                        phase: 'build',
                        planned_km: 32,
                        type: 'lookahead',
                    }),
                    week({
                        week_start: '2026-08-24',
                        phase: 'deload',
                        planned_km: 20,
                        type: 'lookahead',
                    }),
                    week({
                        week_start: '2026-08-31',
                        phase: 'build',
                        planned_km: 30,
                        type: 'lookahead',
                    }),
                ]}
            />,
        );

        // Two distinct Build clusters (before and after the Deload week), not merged across the gap:
        // the first merges its two consecutive Build weeks (30 + 32), the second is the lone Build after Deload.
        expect(screen.getByText('Build · 62 km')).toBeInTheDocument();
        expect(screen.getByText('Deload · 20 km')).toBeInTheDocument();
        expect(screen.getByText('Build · 30 km')).toBeInTheDocument();
        expect(screen.queryByText(/^Peak/)).not.toBeInTheDocument();
        expect(screen.queryByText(/^Taper/)).not.toBeInTheDocument();
    });

    it('shows a "logged so far" line only once some non-lookahead volume has been planned', () => {
        const { rerender } = render(
            <SeasonPhaseBar
                weeks={[week({ type: 'lookahead', planned_km: 30 })]}
            />,
        );
        expect(screen.queryByText(/logged/)).not.toBeInTheDocument();

        rerender(
            <SeasonPhaseBar
                weeks={[
                    week({ type: 'history', planned_km: 30, actual_km: 28 }),
                ]}
            />,
        );
        expect(
            screen.getByText(
                '28 km logged of 30 km planned so far this season.',
            ),
        ).toBeInTheDocument();
    });
});
