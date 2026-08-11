import { router } from '@inertiajs/react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import { setMockPage } from '@/test/setup';

import RunLenses from './RunLenses';

function makeAnalysis(
    id: number,
    type: AnalysisPayload['type'],
    status: 'done' | 'pending' = 'done',
    content: string | null = 'Analysis result.',
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

function claimsAnalysis(
    claims: Array<{
        anchor: string;
        text: string;
        value?: string | null;
        delta?: string | null;
    }>,
    id = 2,
): AnalysisPayload {
    return makeAnalysis(id, 'run_insight', 'done', JSON.stringify(claims));
}

const oneClaim = [
    {
        anchor: 'split:3',
        text: 'Km 3 was the fastest of the run.',
        value: '5:32/km',
        delta: null,
    },
];

const defaultProps = {
    story: makeAnalysis(1, 'post_run_speech', 'done', "This run's story."),
    insight: claimsAnalysis(oneClaim),
};

describe('RunLenses', () => {
    it('renders both lens cards with their labels', () => {
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(screen.getByText("This run's story")).toBeInTheDocument();
        expect(screen.getByText('What stood out')).toBeInTheDocument();
    });

    it('renders the story analysis content when status is done', () => {
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(screen.getByText("This run's story.")).toBeInTheDocument();
    });

    it('renders each claim, with its value shown inline', () => {
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(
            screen.getByText('Km 3 was the fastest of the run.'),
        ).toBeInTheDocument();
        expect(screen.getByText('5:32/km')).toBeInTheDocument();
    });

    it('renders a delta chip alongside a value when both are present', () => {
        const props = {
            ...defaultProps,
            insight: claimsAnalysis([
                {
                    anchor: 'metric:decoupling',
                    text: 'Decoupling ran high.',
                    value: '+12%',
                    delta: '-2% vs 28d avg',
                },
            ]),
        };
        render(<RunLenses {...props} isChainHead />);
        expect(screen.getByText('+12%')).toBeInTheDocument();
        expect(screen.getByText('-2% vs 28d avg')).toBeInTheDocument();
    });

    it('renders multiple claims in order', () => {
        const props = {
            ...defaultProps,
            insight: claimsAnalysis([
                { anchor: 'split:2', text: 'First claim.' },
                { anchor: 'zone:z2', text: 'Second claim.' },
            ]),
        };
        render(<RunLenses {...props} isChainHead />);
        expect(screen.getByText('First claim.')).toBeInTheDocument();
        expect(screen.getByText('Second claim.')).toBeInTheDocument();
    });

    it('hides the insight card entirely when the claims list is empty', () => {
        const props = { ...defaultProps, insight: claimsAnalysis([]) };
        render(<RunLenses {...props} isChainHead />);
        expect(screen.getByText("This run's story")).toBeInTheDocument();
        expect(screen.queryByText('What stood out')).not.toBeInTheDocument();
    });

    it('still shows the insight card while it is pending (nothing to parse yet)', () => {
        const props = {
            ...defaultProps,
            insight: makeAnalysis(2, 'run_insight', 'pending'),
        };
        render(<RunLenses {...props} isChainHead />);
        expect(screen.getByText('What stood out')).toBeInTheDocument();
    });

    it('shows the head-only "Reread all" button on the chain head', () => {
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(screen.getByText(/Reread all/i)).toBeInTheDocument();
    });

    it('hides the "Reread all" button on a historical (non-head) run', () => {
        render(<RunLenses {...defaultProps} />);
        expect(screen.queryByText(/Reread all/i)).not.toBeInTheDocument();
    });

    it('hides the head "Reread all" button when AI is globally paused', () => {
        setMockPage({ aiPaused: true });
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(screen.queryByText(/Reread all/i)).not.toBeInTheDocument();
    });

    it('disables the bulk trigger button while pending', () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => {})),
        );
        render(<RunLenses {...defaultProps} isChainHead />);
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
        render(<RunLenses {...defaultProps} isChainHead />);

        fireEvent.click(
            screen.getByText(/Reread all/i).closest('button') as Element,
        );

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalledWith({
                only: ['speechAnalysis', 'runInsight'],
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
        render(<RunLenses {...defaultProps} isChainHead />);
        // The single "Reread all" control replaces every per-lens "Reread".
        expect(screen.queryByText(/^Reread$/i)).not.toBeInTheDocument();
        expect(screen.getByText(/Reread all/i)).toBeInTheDocument();
    });

    it('shows the shared cooldown countdown on the bulk button', () => {
        const cooling = {
            ...defaultProps,
            story: { ...defaultProps.story, retry_after_seconds: 120 },
        };
        render(<RunLenses {...cooling} isChainHead />);
        const button = screen.getByRole('button', {
            name: /Wait 2:00 before rereading all/i,
        });
        expect(button).toBeDisabled();
        expect(button.textContent).toContain('2:00');
    });
});
