import type { AnalysisPayload } from '@/types/inertia';

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TemariTake from './TemariTake';

function analysis(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: 1,
        status: 'done',
        content: 'Base has been steady, no red flags.',
        type: 'plan_week_voice',
        is_zone_dependent: false,
        subject_type: 'plan_adaptation',
        subject_id: 1,
        discriminator: null,
        ...overrides,
    } as AnalysisPayload;
}

describe('TemariTake', () => {
    it('labels the block and renders the narration as serif italic', () => {
        render(<TemariTake analysis={analysis()} />);

        expect(screen.getByText("Temari's take")).toBeInTheDocument();
        const narration = screen.getByText(
            'Base has been steady, no red flags.',
        );
        expect(narration).toHaveClass('font-serif', 'italic');
    });

    it('keeps its empty state when the narration has not been generated yet', () => {
        render(<TemariTake analysis={analysis({ status: 'pending' })} />);

        expect(screen.getByText("Temari's take")).toBeInTheDocument();
        expect(
            screen.queryByText('Base has been steady, no red flags.'),
        ).not.toBeInTheDocument();
    });
});
