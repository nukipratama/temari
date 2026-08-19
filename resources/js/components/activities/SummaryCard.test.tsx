import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import SummaryCard from './SummaryCard';

const baseAnalysis = (
    overrides: Partial<AnalysisPayload> = {},
): AnalysisPayload => ({
    id: 1,
    status: 'pending',
    content: null,
    type: 'weekly_recap',
    subject_type: String.raw`App\Models\WeeklySnapshot`,
    subject_id: 100,
    discriminator: null,
    ...overrides,
});

describe('SummaryCard', () => {
    it('shows the fallback prose when the analysis is not yet done', () => {
        render(
            <SummaryCard
                analysis={baseAnalysis()}
                fallback="You ran 3x this week for 12.5km."
            />,
        );
        expect(
            screen.getByText('You ran 3x this week for 12.5km.'),
        ).toBeInTheDocument();
    });

    it('renders the LLM-generated narrative when the analysis is done', () => {
        const done = baseAnalysis({
            status: 'done',
            content:
                'You ran three times this week, and your pace kept getting smoother.',
        });
        render(<SummaryCard analysis={done} fallback="ignored" />);
        expect(
            screen.getByText(
                'You ran three times this week, and your pace kept getting smoother.',
            ),
        ).toBeInTheDocument();
        // Fallback should not double-render when the LLM content is available.
        expect(screen.queryByText('ignored')).not.toBeInTheDocument();
    });

    it('hides the manual trigger but keeps the fallback prose for a past week with no narration yet', () => {
        render(<SummaryCard analysis={baseAnalysis()} fallback="fallback" />);
        expect(
            screen.queryByRole('button', { name: /Ask Temari to read it/ }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('fallback')).toBeInTheDocument();
    });

    it('suppresses the trigger and labels the fallback as a preview for the current week', () => {
        render(
            <SummaryCard
                analysis={baseAnalysis()}
                fallback="fallback"
                awaitingSchedule
            />,
        );
        expect(
            screen.getByText(/This week's recap isn't available yet/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Ask Temari to read it/ }),
        ).not.toBeInTheDocument();
        // Still visible (never-empty intent preserved) but framed as a preview
        // instead of stacking unlabeled under "not available yet" — that read as a
        // contradiction (not ready yet, immediately followed by the summary).
        expect(screen.getByText('For now:')).toBeInTheDocument();
        expect(screen.getByText('fallback')).toBeInTheDocument();
    });
});
