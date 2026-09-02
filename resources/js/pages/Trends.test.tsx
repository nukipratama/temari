import type { ComponentProps } from 'react';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import Trends from './Trends';

function narrationPayload(
    discriminator: '30d' | '90d' | '12mo',
    content: string,
): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content,
        type: 'trend_read',
        is_zone_dependent: true,
        subject_type: 'trend_read_user_range',
        subject_id: 1,
        discriminator,
    };
}

const NARRATION = {
    '30d': narrationPayload('30d', 'Last 30 days.\n\nFitness climbing.'),
    '90d': narrationPayload('90d', 'Last 90 days.\n\nSteady build.'),
    '12mo': narrationPayload('12mo', 'The full year.\n\nA long climb.'),
};

const BASE_PROPS: ComponentProps<typeof Trends> = {
    ctlTrend: [],
    badgeMilestones: [],
    streak: {
        weeks: 0,
        rest_weeks_held: 0,
        rest_weeks_cap: 2,
        ran_this_week: false,
        week_ends_on: '2026-08-30',
    },
    narration: NARRATION,
};

/** A year of daily points, so every range window has data to slice. */
function yearOfTrend() {
    return Array.from({ length: 365 }, (_, i) => ({
        date: `2026-01-${String((i % 28) + 1).padStart(2, '0')}`,
        ctl: 40 + i * 0.05,
        atl: 35,
    }));
}

describe('Trends', () => {
    it('renders the page headline', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('how things')).toBeInTheDocument();
        expect(screen.getByText('are going.')).toBeInTheDocument();
    });

    it('renders exactly the four prototype blocks', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('Trends')).toBeInTheDocument();
        expect(
            screen.getByRole('group', { name: 'Time range' }),
        ).toBeInTheDocument();
        expect(screen.getByText("Temari's read")).toBeInTheDocument();
        expect(
            screen.getByText(/not enough training history yet/),
        ).toBeInTheDocument();
    });

    it('defaults to the 12 month range narration', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('The full year.')).toBeInTheDocument();
    });

    it('switches the narration shown when the range toggle changes', () => {
        render(<Trends {...BASE_PROPS} />);

        fireEvent.click(screen.getByRole('button', { name: '30 days' }));

        expect(screen.getByText('Last 30 days.')).toBeInTheDocument();
        expect(screen.queryByText('The full year.')).not.toBeInTheDocument();
    });

    it('re-windows the fitness panel when the range toggle changes', () => {
        render(<Trends {...BASE_PROPS} ctlTrend={yearOfTrend()} />);

        expect(
            screen.getByRole('img', { name: /over 365 days/ }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: '30 days' }));

        expect(
            screen.getByRole('img', { name: /over 30 days/ }),
        ).toBeInTheDocument();
    });

    it('shows the week streak as a chip inside the fitness panel', () => {
        render(
            <Trends
                {...BASE_PROPS}
                ctlTrend={yearOfTrend()}
                streak={{
                    weeks: 6,
                    rest_weeks_held: 0,
                    rest_weeks_cap: 2,
                    ran_this_week: true,
                    week_ends_on: '2026-08-30',
                }}
            />,
        );

        expect(
            screen.getByRole('button', { name: /6-week streak/ }),
        ).toBeInTheDocument();
    });
});
