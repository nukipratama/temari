import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import TemariTake from './TemariTake';

function analysis(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'Base has been steady, no red flags.',
        type: 'plan_season_voice',
        is_zone_dependent: false,
        subject_type: 'season',
        subject_id: 1,
        discriminator: null,
        ...overrides,
    } as AnalysisPayload;
}

describe('TemariTake', () => {
    it('labels the block and renders the narration in the prose register', () => {
        render(<TemariTake analysis={analysis()} />);

        expect(screen.getByText("Temari's take")).toBeInTheDocument();
        const narration = screen.getByText(
            'Base has been steady, no red flags.',
        );
        expect(narration).toHaveClass('narration');
    });

    it('keeps its empty state when the narration has not been generated yet', () => {
        render(<TemariTake analysis={analysis({ status: 'pending' })} />);

        expect(screen.getByText("Temari's take")).toBeInTheDocument();
        expect(
            screen.queryByText('Base has been steady, no red flags.'),
        ).not.toBeInTheDocument();
    });

    /** #939: the day row overrides the label to "Temari's read"; every other caller keeps the default. */
    it('renders a custom label in place of the default', () => {
        render(<TemariTake analysis={analysis()} label="Temari's read" />);

        expect(screen.getByText("Temari's read")).toBeInTheDocument();
        expect(screen.queryByText("Temari's take")).not.toBeInTheDocument();
    });
});
