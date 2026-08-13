import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { RunQuestion } from '@/hooks/useRunQuestions';

import AskAboutRun from './AskAboutRun';

function row(overrides: Partial<RunQuestion> = {}): RunQuestion {
    return {
        id: 1,
        activity_id: 9,
        question: 'why did my heart rate drift up?',
        answer: null,
        status: 'queued',
        asked_at: '2026-08-13T10:00:00+00:00',
        ...overrides,
    };
}

/** GET returns the thread, POST returns whatever `post` is set to. */
function stubApi(
    thread: { questions: RunQuestion[]; suggestions: string[] },
    post: Response = new Response(JSON.stringify(row()), { status: 201 }),
) {
    const fetchMock = vi.fn((_url: string, init?: RequestInit) =>
        Promise.resolve(
            init?.method === 'POST'
                ? post.clone()
                : new Response(JSON.stringify(thread), { status: 200 }),
        ),
    );
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

describe('AskAboutRun', () => {
    it('offers the suggestions this run supports', async () => {
        stubApi({
            questions: [],
            suggestions: [
                'why did my heart rate drift up?',
                'which km cost me the most?',
            ],
        });

        render(<AskAboutRun activityId={9} />);

        expect(
            await screen.findByRole('button', {
                name: 'why did my heart rate drift up?',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'which km cost me the most?' }),
        ).toBeInTheDocument();
    });

    it('drops a suggestion that has already been asked', async () => {
        stubApi({
            questions: [row({ status: 'done', answer: 'Heat.' })],
            suggestions: [
                'Why did my heart rate drift up?',
                'which km cost me the most?',
            ],
        });

        render(<AskAboutRun activityId={9} />);

        await screen.findByRole('button', {
            name: 'which km cost me the most?',
        });
        expect(
            screen.queryByRole('button', {
                name: 'Why did my heart rate drift up?',
            }),
        ).not.toBeInTheDocument();
    });

    it('sends the typed question and clears the box', async () => {
        const fetchMock = stubApi({ questions: [], suggestions: [] });

        render(<AskAboutRun activityId={9} />);
        await waitFor(() => expect(fetchMock).toHaveBeenCalled());

        const input = screen.getByPlaceholderText(
            'Ask anything about this run',
        );
        fireEvent.change(input, { target: { value: 'was it the heat?' } });
        fireEvent.click(screen.getByRole('button', { name: /Ask/ }));

        await waitFor(() => expect(input).toHaveValue(''));
        const post = fetchMock.mock.calls.find(
            (call) => call[1]?.method === 'POST',
        );
        expect(post?.[1]?.body).toBe('{"question":"was it the heat?"}');
    });

    it('sends a suggestion straight through on tap', async () => {
        const fetchMock = stubApi({
            questions: [],
            suggestions: ['how much did the heat cost me?'],
        });

        render(<AskAboutRun activityId={9} />);
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'how much did the heat cost me?',
            }),
        );

        await waitFor(() =>
            expect(
                fetchMock.mock.calls.some((call) => call[1]?.method === 'POST'),
            ).toBe(true),
        );
    });

    it('keeps the ask button disabled until the question is long enough', async () => {
        stubApi({ questions: [], suggestions: [] });

        render(<AskAboutRun activityId={9} />);
        const button = await screen.findByRole('button', { name: /Ask/ });

        expect(button).toBeDisabled();
        fireEvent.change(
            screen.getByPlaceholderText('Ask anything about this run'),
            { target: { value: 'hi' } },
        );
        expect(button).toBeDisabled();
        fireEvent.change(
            screen.getByPlaceholderText('Ask anything about this run'),
            { target: { value: 'was it the heat?' } },
        );
        expect(button).toBeEnabled();
    });

    it('says so plainly when the rate limit is hit', async () => {
        stubApi(
            { questions: [], suggestions: [] },
            new Response('{}', { status: 429 }),
        );

        render(<AskAboutRun activityId={9} />);
        fireEvent.change(
            await screen.findByPlaceholderText('Ask anything about this run'),
            { target: { value: 'was it the heat?' } },
        );
        fireEvent.click(screen.getByRole('button', { name: /Ask/ }));

        expect(
            await screen.findByText(/asking faster than I can think/),
        ).toBeInTheDocument();
    });

    it('says generation is paused rather than pretending the question landed', async () => {
        stubApi(
            { questions: [], suggestions: [] },
            new Response('{"error":"generation_paused"}', { status: 409 }),
        );

        render(<AskAboutRun activityId={9} />);
        fireEvent.change(
            await screen.findByPlaceholderText('Ask anything about this run'),
            { target: { value: 'was it the heat?' } },
        );
        fireEvent.click(screen.getByRole('button', { name: /Ask/ }));

        expect(
            await screen.findByText(/Generation is paused/),
        ).toBeInTheDocument();
    });

    it('renders a pending answer as pending, not as an empty answer', async () => {
        stubApi({
            questions: [row({ status: 'processing' })],
            suggestions: [],
        });

        render(<AskAboutRun activityId={9} />);

        expect(
            await screen.findByText('Thinking about it.'),
        ).toBeInTheDocument();
    });

    it('renders a done answer', async () => {
        stubApi({
            questions: [
                row({
                    status: 'done',
                    answer: 'You went out **hot** and paid for it late.',
                }),
            ],
            suggestions: [],
        });

        render(<AskAboutRun activityId={9} />);

        expect(await screen.findByText(/paid for it late/)).toBeInTheDocument();
        expect(screen.getByText('hot')).toBeInTheDocument();
    });

    it('offers to re-ask a failed question by refilling the box', async () => {
        stubApi({
            questions: [row({ status: 'failed' })],
            suggestions: [],
        });

        render(<AskAboutRun activityId={9} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Ask it again' }),
        );

        expect(
            screen.getByPlaceholderText('Ask anything about this run'),
        ).toHaveValue('why did my heart rate drift up?');
    });

    it('warns that a summary-only run has a smaller toolbox', async () => {
        stubApi({ questions: [], suggestions: [] });

        render(<AskAboutRun activityId={9} summaryOnly />);

        expect(
            await screen.findByText(/no splits,\s+zones or terrain yet/),
        ).toBeInTheDocument();
    });

    it('stays quiet about the toolbox on a fully hydrated run', async () => {
        stubApi({ questions: [], suggestions: [] });

        render(<AskAboutRun activityId={9} />);
        await screen.findByPlaceholderText('Ask anything about this run');

        expect(
            screen.queryByText(/zones or terrain yet/),
        ).not.toBeInTheDocument();
    });

    it('still offers the ask box when the thread fails to load', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response('', { status: 500 })),
        );

        render(<AskAboutRun activityId={9} />);

        expect(
            await screen.findByPlaceholderText('Ask anything about this run'),
        ).toBeInTheDocument();
    });
});
