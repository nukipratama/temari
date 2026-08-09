import { router } from '@inertiajs/react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import { setMockPage } from '@/test/setup';

import FourLensGrid from './FourLensGrid';

function makeAnalysis(
    id: number,
    type: AnalysisPayload['type'],
    status: 'done' | 'pending' = 'done',
    content = 'Analysis result.',
): AnalysisPayload {
    return {
        id,
        status,
        content: status === 'done' ? content : null,
        type,
        subject_type: 'Activity',
        subject_id: 1,
        discriminator: null,
    };
}

const defaultProps = {
    cerita: makeAnalysis(1, 'post_run_speech', 'done', "This run's story."),
    terjemahan: makeAnalysis(
        2,
        'run_insight_technical',
        'done',
        'Technical translation.',
    ),
    split: makeAnalysis(3, 'run_insight_splits', 'done', 'Split per km.'),
    hr: makeAnalysis(4, 'run_insight_zones', 'done', 'HR Zones.'),
};

describe('FourLensGrid', () => {
    it('renders the four lens cards with their labels', () => {
        render(<FourLensGrid {...defaultProps} isChainHead />);
        expect(screen.getByText("This run's story")).toBeInTheDocument();
        expect(screen.getByText('Technical translation')).toBeInTheDocument();
        expect(screen.getByText('Most interesting split')).toBeInTheDocument();
        expect(screen.getByText('HR Zones')).toBeInTheDocument();
    });

    it('renders analysis content when status is done', () => {
        render(<FourLensGrid {...defaultProps} isChainHead />);
        expect(screen.getByText("This run's story.")).toBeInTheDocument();
    });

    it('shows the head-only "Reread all" button on the chain head', () => {
        render(<FourLensGrid {...defaultProps} isChainHead />);
        expect(screen.getByText(/Reread all/i)).toBeInTheDocument();
    });

    it('hides the "Reread all" button on a historical (non-head) run', () => {
        render(<FourLensGrid {...defaultProps} />);
        expect(screen.queryByText(/Reread all/i)).not.toBeInTheDocument();
    });

    it('hides the head "Reread all" button when AI is globally paused', () => {
        setMockPage({ aiPaused: true });
        render(<FourLensGrid {...defaultProps} isChainHead />);
        expect(screen.queryByText(/Reread all/i)).not.toBeInTheDocument();
    });

    it('disables the bulk trigger button while pending', () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => {})),
        );
        render(<FourLensGrid {...defaultProps} isChainHead />);
        fireEvent.click(
            screen.getByText(/Reread all/i).closest('button') as Element,
        );
        expect(screen.getByText(/Rereading/i)).toBeInTheDocument();
    });

    it('reloads via inertia and re-enables the button once the bulk trigger settles', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(new Response('{}', { status: 200 }))),
        );
        render(<FourLensGrid {...defaultProps} isChainHead />);

        fireEvent.click(
            screen.getByText(/Reread all/i).closest('button') as Element,
        );

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalledWith({
                only: [
                    'speechAnalysis',
                    'insightTechnical',
                    'insightSplits',
                    'insightZones',
                ],
            });
        });
        await waitFor(() => {
            expect(screen.getByText('Reread all')).toBeInTheDocument();
        });
        expect(
            screen.getByText(/Reread all/i).closest('button'),
        ).not.toBeDisabled();
    });

    it('drops the per-lens reanalyze buttons on the head run', () => {
        render(<FourLensGrid {...defaultProps} isChainHead />);
        // The single "Reread all" control replaces every per-lens "Reread".
        expect(screen.queryByText(/^Reread$/i)).not.toBeInTheDocument();
        expect(screen.getByText(/Reread all/i)).toBeInTheDocument();
    });

    it('shows the shared cooldown countdown on the bulk button', () => {
        const cooling = {
            ...defaultProps,
            cerita: { ...defaultProps.cerita, retry_after_seconds: 120 },
        };
        render(<FourLensGrid {...cooling} isChainHead />);
        const button = screen.getByRole('button', {
            name: /Wait 2:00 before rereading all/i,
        });
        expect(button).toBeDisabled();
        expect(button.textContent).toContain('2:00');
    });
});
