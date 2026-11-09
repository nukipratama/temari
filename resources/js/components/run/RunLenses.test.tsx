import { router } from '@inertiajs/react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type {
    AnalysisPayload,
    RunClaim,
    SpeechAnalysisPayload,
} from '@/types/inertia';

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
        title: null,
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
    mood: 'easy' as const,
    story: makeAnalysis(1, 'post_run_speech', 'done', "This run's story."),
    insight: claimsAnalysis(oneClaim),
};

describe('RunLenses', () => {
    it('thinks in the header while either lens is being written', () => {
        const { container } = render(
            <RunLenses
                {...defaultProps}
                insight={{
                    ...makeAnalysis(2, 'run_insight', 'pending'),
                    status: 'processing',
                }}
            />,
        );

        expect(
            container
                .querySelector('svg[data-mascot]')
                ?.getAttribute('data-mascot'),
        ).toBe('thinking');
    });

    it('tags the voice header with a 40px mascot posed to the run mood', () => {
        const { container } = render(
            <RunLenses {...defaultProps} mood="overloaded" />,
        );
        const mascot = container.querySelector('svg[data-mascot]');

        expect(mascot?.getAttribute('data-mascot')).toBe('overloaded');
        expect(mascot?.getAttribute('width')).toBe('40');
    });

    it('heads the one voice card and labels both halves of it', () => {
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(
            screen.getByRole('heading', { name: 'What Temari says' }),
        ).toBeInTheDocument();
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

    it('still draws the anchor button when the claim has empty-string value and delta', () => {
        const props = {
            ...defaultProps,
            insight: claimsAnalysis([
                {
                    anchor: 'split:3',
                    text: 'Km 3 was the fastest.',
                    value: '',
                    delta: '',
                },
            ]),
        };
        render(
            <RunLenses
                {...props}
                isChainHead
                drawnAnchors={new Set(['split:3'])}
            />,
        );

        expect(
            screen.getByRole('button', { name: /show .* on this page/i }),
        ).toBeInTheDocument();
    });

    it('draws no chip row for a claim whose value and delta are empty strings and has no anchor on the page', () => {
        const props = {
            ...defaultProps,
            insight: claimsAnalysis([
                {
                    anchor: 'split:3',
                    text: 'Km 3 was the fastest.',
                    value: '',
                    delta: '',
                },
            ]),
        };
        render(<RunLenses {...props} isChainHead />);

        const claim = screen
            .getByText('Km 3 was the fastest.')
            .closest('.narration');
        expect(claim?.parentElement?.children).toHaveLength(1);
    });

    it('renders two claims that share an anchor', () => {
        const props = {
            ...defaultProps,
            insight: claimsAnalysis([
                { anchor: 'split:3', text: 'First on km 3.' },
                { anchor: 'split:3', text: 'Second on km 3.' },
            ]),
        };
        render(<RunLenses {...props} isChainHead />);

        expect(screen.getByText('First on km 3.')).toBeInTheDocument();
        expect(screen.getByText('Second on km 3.')).toBeInTheDocument();
    });

    it('drops parsed claims that are not well-formed objects', () => {
        render(
            <RunLenses
                mood="easy"
                story={makeAnalysis(1, 'post_run_speech', 'done')}
                insight={makeAnalysis(
                    2,
                    'run_insight',
                    'done',
                    JSON.stringify([
                        null,
                        'junk',
                        { anchor: 'split:1' },
                        { anchor: 'split:3', text: 'Km 3 was the fastest.' },
                    ]),
                )}
            />,
        );

        const claimText = screen.getByText('Km 3 was the fastest.');
        expect(claimText).toBeInTheDocument();
        expect(
            claimText.closest('.narration')?.parentElement?.parentElement
                ?.children,
        ).toHaveLength(1);
        expect(screen.queryByText('junk')).not.toBeInTheDocument();
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

    it('hides the claims half entirely when the claims list is empty', () => {
        const props = { ...defaultProps, insight: claimsAnalysis([]) };
        render(<RunLenses {...props} isChainHead />);
        expect(screen.getByText("This run's story")).toBeInTheDocument();
        expect(screen.queryByText('What stood out')).not.toBeInTheDocument();
    });

    it('hides the claims label while the insight is pending and empty', () => {
        const props = {
            ...defaultProps,
            insight: makeAnalysis(2, 'run_insight', 'pending'),
        };
        const { container } = render(<RunLenses {...props} isChainHead />);
        expect(screen.queryByText('What stood out')).not.toBeInTheDocument();
        expect(container.querySelector('.border-dashed')).toBeNull();
        expect(screen.getByText("This run's story")).toBeInTheDocument();
    });

    it('hides the story label while the story is pending and empty', () => {
        const props = {
            ...defaultProps,
            story: makeAnalysis(1, 'post_run_speech', 'pending'),
        };
        render(<RunLenses {...props} isChainHead />);
        expect(screen.queryByText("This run's story")).not.toBeInTheDocument();
        expect(screen.getByText('What stood out')).toBeInTheDocument();
    });

    it.each(['queued', 'processing', 'failed'] as const)(
        'keeps both labels while a block is %s',
        (status) => {
            const props = {
                ...defaultProps,
                story: {
                    ...makeAnalysis(1, 'post_run_speech', 'pending'),
                    status,
                },
                insight: {
                    ...makeAnalysis(2, 'run_insight', 'pending'),
                    status,
                },
            };
            render(<RunLenses {...props} isChainHead />);
            expect(screen.getByText("This run's story")).toBeInTheDocument();
            expect(screen.getByText('What stood out')).toBeInTheDocument();
        },
    );

    it('draws no "What Temari says" section when both lenses are empty', () => {
        render(
            <RunLenses
                mood="easy"
                story={makeAnalysis(1, 'post_run_speech', 'pending')}
                insight={makeAnalysis(2, 'run_insight', 'pending')}
                isChainHead
            />,
        );
        expect(
            screen.queryByRole('heading', { name: 'What Temari says' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText("This run's story")).not.toBeInTheDocument();
        expect(screen.queryByText('What stood out')).not.toBeInTheDocument();
    });

    it('keeps the section when only the insight claims are empty and the story is done', () => {
        render(
            <RunLenses
                {...defaultProps}
                insight={claimsAnalysis([])}
                isChainHead
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'What Temari says' }),
        ).toBeInTheDocument();
    });

    it('shows the head-only reread control on the chain head', () => {
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(
            screen.getByRole('button', { name: /reread/i }),
        ).toBeInTheDocument();
    });

    it('hides the reread control on a historical (non-head) run', () => {
        render(<RunLenses {...defaultProps} />);
        expect(
            screen.queryByRole('button', { name: /reread/i }),
        ).not.toBeInTheDocument();
    });

    it('hides the head reread control when AI is globally paused', () => {
        setMockPage({ aiPaused: true });
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(
            screen.queryByRole('button', { name: /reread/i }),
        ).not.toBeInTheDocument();
    });

    it('disables the bulk trigger button while pending', () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => {})),
        );
        render(<RunLenses {...defaultProps} isChainHead />);
        fireEvent.click(screen.getByRole('button', { name: /reread/i }));
        expect(screen.getByText(/Rereading/i)).toBeInTheDocument();
    });

    it('reloads via inertia and re-enables the button once the bulk trigger settles', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.resolve(new Response('{}', { status: 200 }))),
        );
        render(<RunLenses {...defaultProps} isChainHead />);

        fireEvent.click(screen.getByRole('button', { name: /reread/i }));

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalledWith({
                only: ['speechAnalysis', 'runInsight'],
            });
        });
        await waitFor(() => {
            expect(screen.getByText('reread')).toBeInTheDocument();
        });
        expect(
            screen.getByRole('button', { name: /reread/i }),
        ).not.toBeDisabled();
    });

    it('carries exactly one reread control for both halves of the card', () => {
        render(<RunLenses {...defaultProps} isChainHead />);
        expect(screen.getAllByRole('button', { name: /reread/i })).toHaveLength(
            1,
        );
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
        expect(button.textContent).toContain('next in 2:00');
    });
});

function mergedStory(
    overrides: Partial<SpeechAnalysisPayload> = {},
): SpeechAnalysisPayload {
    return {
        ...makeAnalysis(1, 'post_run_speech', 'done', MERGED_BODY),
        title: 'the last two km paid for the first three',
        claims: MERGED_CLAIMS,
        ...overrides,
    };
}

const MERGED_BODY = [
    'You went out at 5:40 and held it for three km.',
    'The heart rate climbed through km 4 and the pace gave way after it.',
]
    .map((paragraph) => `${paragraph} ${paragraph} ${paragraph}`)
    .join('\n\n');

const MERGED_CLAIMS: RunClaim[] = [
    {
        anchor: 'split:3',
        text: 'Km 3 was the last one at the opening pace.',
        value: '5:40/km',
        delta: null,
    },
    {
        anchor: 'metric:decoupling',
        text: 'Heart rate pulled away from pace in the second half.',
        value: '+9%',
        delta: '+4% vs 28d avg',
    },
];

describe('RunLenses merged story', () => {
    it('renders one block: the headline, the body at prose width, then the claims', () => {
        const { container } = render(
            <RunLenses
                mood="wobbly"
                story={mergedStory()}
                insight={makeAnalysis(2, 'run_insight', 'pending')}
                isChainHead
            />,
        );

        const headline = screen.getByText(
            'the last two km paid for the first three',
        );
        const body = container.querySelector('p.narration');
        const firstClaim = screen.getByText(
            'Km 3 was the last one at the opening pace.',
        );

        expect(body?.textContent).toBe(MERGED_BODY);
        expect(body?.closest('.whitespace-pre-line')).not.toBeNull();
        expect(body?.closest('[class*="[&_p]:max-w-prose"]')).toContainElement(
            headline,
        );
        expect(
            headline.compareDocumentPosition(firstClaim) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(screen.getByText('+9%')).toBeInTheDocument();
        expect(screen.getByText('+4% vs 28d avg')).toBeInTheDocument();
        expect(screen.queryByText("This run's story")).toBeNull();
        expect(screen.queryByText('What stood out')).toBeNull();
    });

    it('hides an old run insight block once the story is merged', () => {
        render(
            <RunLenses
                mood="easy"
                story={mergedStory()}
                insight={claimsAnalysis(oneClaim)}
            />,
        );

        expect(screen.queryByText('What stood out')).toBeNull();
        expect(
            screen.queryByText('Km 3 was the fastest of the run.'),
        ).toBeNull();
    });

    it('treats a story with claims but no headline as merged, the rule-based fallback', () => {
        render(
            <RunLenses
                mood="easy"
                story={mergedStory({ title: null })}
                insight={claimsAnalysis(oneClaim)}
            />,
        );

        expect(
            screen.getByText('Km 3 was the last one at the opening pace.'),
        ).toBeInTheDocument();
        expect(screen.queryByText("This run's story")).toBeNull();
        expect(screen.queryByText('What stood out')).toBeNull();
    });

    it('draws no claims list for a merged story with none', () => {
        const { container } = render(
            <RunLenses
                mood="easy"
                story={mergedStory({ claims: [] })}
                insight={makeAnalysis(2, 'run_insight', 'pending')}
            />,
        );

        expect(
            screen.getByText('the last two km paid for the first three'),
        ).toBeInTheDocument();
        expect(container.querySelector('.border-dashed')).toBeNull();
    });

    it('keeps tap-to-scroll on a merged claim the page draws, and no control for one it does not', () => {
        const target = document.createElement('div');
        target.id = 'anchor-split-3';
        const scrollIntoView = vi.fn();
        target.scrollIntoView = scrollIntoView;
        document.body.append(target);

        render(
            <RunLenses
                mood="easy"
                story={mergedStory()}
                insight={makeAnalysis(2, 'run_insight', 'pending')}
                drawnAnchors={new Set(['split:3'])}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Show km 3 on this page' }),
        );

        expect(scrollIntoView).toHaveBeenCalledWith({
            block: 'center',
            behavior: 'smooth',
        });
        expect(target.dataset.anchorHit).toBe('true');
        expect(
            screen.queryByRole('button', { name: /decoupling/i }),
        ).toBeNull();
        target.remove();
    });

    it('rereads the story alone, never the retired run insight', async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve(new Response('{}', { status: 200 })),
        );
        vi.stubGlobal('fetch', fetchMock);
        render(
            <RunLenses
                mood="easy"
                story={makeAnalysis(1, 'post_run_speech', 'done', 'Old.')}
                insight={claimsAnalysis(oneClaim)}
                isChainHead
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /reread/i }));

        await waitFor(() => {
            expect(router.reload).toHaveBeenCalled();
        });
        const urls = fetchMock.mock.calls.map((call) =>
            String((call as unknown[])[0]),
        );
        expect(urls).toHaveLength(1);
        expect(urls[0]).toContain('/api/analyses/post_run_speech/1/trigger');
    });

    it('offers no retry on an old run insight block that failed', () => {
        render(
            <RunLenses
                mood="easy"
                story={makeAnalysis(1, 'post_run_speech', 'done', 'Old.')}
                insight={{
                    ...makeAnalysis(2, 'run_insight', 'pending'),
                    status: 'failed',
                }}
            />,
        );

        expect(screen.getByText('What stood out')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /try again/i })).toBeNull();
    });

    it('does not think in the header for an old insight a merged story hides', () => {
        const { container } = render(
            <RunLenses
                mood="blazing"
                story={mergedStory()}
                insight={{
                    ...makeAnalysis(2, 'run_insight', 'pending'),
                    status: 'processing',
                }}
            />,
        );

        expect(
            container
                .querySelector('svg[data-mascot]')
                ?.getAttribute('data-mascot'),
        ).toBe('blazing');
    });
});

describe('RunLenses claim citations', () => {
    /**
     * The narrator validates an anchor against the run's StreamSummary, which
     * holds readings no component on the page draws. Offering a control for one
     * of those would be a button that goes nowhere.
     */
    it('offers no control for a claim the page has no site for', () => {
        render(
            <RunLenses
                {...defaultProps}
                drawnAnchors={new Set(['split:3'])}
                insight={claimsAnalysis([
                    { anchor: 'metric:cadence_drop', text: 'Cadence fell.' },
                ])}
            />,
        );

        expect(screen.getByText('Cadence fell.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Show/ })).toBeNull();
    });

    it('names what it points at, so the reader knows before tapping', () => {
        render(
            <RunLenses {...defaultProps} drawnAnchors={new Set(['split:3'])} />,
        );

        expect(
            screen.getByRole('button', { name: 'Show km 3 on this page' }),
        ).toBeInTheDocument();
    });

    it('scrolls to the element that draws the claim and marks it', () => {
        const target = document.createElement('div');
        target.id = 'anchor-split-3';
        const scrollIntoView = vi.fn();
        target.scrollIntoView = scrollIntoView;
        document.body.append(target);

        render(
            <RunLenses {...defaultProps} drawnAnchors={new Set(['split:3'])} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Show km 3 on this page' }),
        );

        expect(scrollIntoView).toHaveBeenCalledWith({
            block: 'center',
            behavior: 'smooth',
        });
        expect(target.dataset.anchorHit).toBe('true');
        target.remove();
    });
});
