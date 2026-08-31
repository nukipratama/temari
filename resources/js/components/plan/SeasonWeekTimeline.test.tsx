import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { type SeasonSummaryWeek } from './SeasonPhaseBar';
import SeasonWeekTimeline from './SeasonWeekTimeline';

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

// 7 Mondays, the 4th ('2026-08-10') is the current week.
const SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-07-20', type: 'history' }),
    week({ week_start: '2026-07-27', type: 'history' }),
    week({ week_start: '2026-08-03', type: 'history' }),
    week({ week_start: '2026-08-10', type: 'current' }),
    week({ week_start: '2026-08-17', type: 'lookahead' }),
    week({ week_start: '2026-08-24', type: 'lookahead' }),
    week({ week_start: '2026-08-31', type: 'lookahead' }),
];

describe('SeasonWeekTimeline', () => {
    it('renders nothing for an empty season', () => {
        const { container } = render(<SeasonWeekTimeline weeks={[]} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('shows a window around the current week by default, collapsing the rest behind toggles', () => {
        render(<SeasonWeekTimeline weeks={SEASON} />);

        // Visible window: 2 weeks either side of "current" (2026-08-10).
        expect(screen.getByText('Jul 27')).toBeInTheDocument();
        expect(screen.getByText('Aug 3')).toBeInTheDocument();
        expect(screen.getByText('Aug 10')).toBeInTheDocument();
        expect(screen.getByText('Aug 17')).toBeInTheDocument();
        expect(screen.getByText('Aug 24')).toBeInTheDocument();

        // Outside the window, collapsed behind a toggle.
        expect(screen.queryByText('Jul 20')).not.toBeInTheDocument();
        expect(screen.queryByText('Aug 31')).not.toBeInTheDocument();
        expect(screen.getByText('1 weeks earlier')).toBeInTheDocument();
        expect(screen.getByText('1 weeks ahead')).toBeInTheDocument();
    });

    it('reveals earlier weeks when its toggle is clicked', async () => {
        render(<SeasonWeekTimeline weeks={SEASON} />);

        await userEvent.setup().click(screen.getByText('1 weeks earlier'));

        expect(screen.getByText('Jul 20')).toBeInTheDocument();
    });

    it('reveals later weeks when its toggle is clicked', async () => {
        render(<SeasonWeekTimeline weeks={SEASON} />);

        await userEvent.setup().click(screen.getByText('1 weeks ahead'));

        expect(screen.getByText('Aug 31')).toBeInTheDocument();
    });

    it('renders no toggle when every week already fits in the visible window', () => {
        render(
            <SeasonWeekTimeline
                weeks={[
                    week({ week_start: '2026-08-03', type: 'history' }),
                    week({ week_start: '2026-08-10', type: 'current' }),
                    week({ week_start: '2026-08-17', type: 'lookahead' }),
                ]}
            />,
        );

        expect(screen.queryByText(/weeks earlier/)).not.toBeInTheDocument();
        expect(screen.queryByText(/weeks ahead/)).not.toBeInTheDocument();
    });

    it('shows planned-vs-actual once a week has been logged, otherwise the plan alone', () => {
        render(
            <SeasonWeekTimeline
                weeks={[
                    week({
                        week_start: '2026-08-03',
                        type: 'current',
                        planned_km: 30,
                        actual_km: 28,
                    }),
                ]}
            />,
        );

        expect(screen.getByText('28/30 km')).toBeInTheDocument();
    });

    it('shows the plan alone for a week with no logged distance yet', () => {
        render(
            <SeasonWeekTimeline
                weeks={[
                    week({
                        week_start: '2026-08-03',
                        type: 'current',
                        planned_km: 30,
                        actual_km: null,
                    }),
                ]}
            />,
        );

        expect(screen.getByText('30 km')).toBeInTheDocument();
    });
});
