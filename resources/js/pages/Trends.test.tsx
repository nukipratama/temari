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

describe('Trends', () => {
    it('renders the page headline', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(screen.getByText('how things')).toBeInTheDocument();
        expect(screen.getByText('are going.')).toBeInTheDocument();
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

    it('shows the fitness trend empty state when there is no history yet', () => {
        render(<Trends {...BASE_PROPS} />);

        expect(
            screen.getByText(
                'Not enough training history yet to draw a trend.',
            ),
        ).toBeInTheDocument();
    });

    it('renders the streak badge board entry', () => {
        render(
            <Trends
                {...BASE_PROPS}
                streak={{
                    weeks: 6,
                    rest_weeks_held: 0,
                    rest_weeks_cap: 2,
                    ran_this_week: true,
                    week_ends_on: '2026-08-30',
                }}
            />,
        );

        expect(screen.getByText('6 weeks streak')).toBeInTheDocument();
    });
});
