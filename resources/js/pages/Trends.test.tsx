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

describe('Trends', () => {
    it('renders the page headline', () => {
        render(<Trends ctlTrend={[]} narration={NARRATION} />);

        expect(screen.getByText('How things are going')).toBeInTheDocument();
    });

    it('defaults to the 12 month range narration', () => {
        render(<Trends ctlTrend={[]} narration={NARRATION} />);

        expect(screen.getByText('The full year.')).toBeInTheDocument();
    });

    it('switches the narration shown when the range toggle changes', () => {
        render(<Trends ctlTrend={[]} narration={NARRATION} />);

        fireEvent.click(screen.getByRole('button', { name: '30 days' }));

        expect(screen.getByText('Last 30 days.')).toBeInTheDocument();
        expect(screen.queryByText('The full year.')).not.toBeInTheDocument();
    });

    it('shows the fitness trend empty state when there is no history yet', () => {
        render(<Trends ctlTrend={[]} narration={NARRATION} />);

        expect(
            screen.getByText(/Not enough training history yet/),
        ).toBeInTheDocument();
    });
});
