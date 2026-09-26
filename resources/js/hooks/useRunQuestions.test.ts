import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { useRunQuestions, type RunQuestion } from './useRunQuestions';

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

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status });
}

/** A fetch whose every call waits until the test resolves it, in any order. */
function deferredFetch() {
    const resolvers: Array<(response: Response) => void> = [];
    const fetchMock = vi.fn(
        () =>
            new Promise<Response>((resolve) => {
                resolvers.push(resolve);
            }),
    );
    vi.stubGlobal('fetch', fetchMock);

    return {
        fetchMock,
        resolve: async (call: number, response: Response) => {
            await act(async () => {
                resolvers[call](response);
            });
        },
    };
}

describe('useRunQuestions', () => {
    it('loads the thread and this run’s suggestions on mount', async () => {
        const answered = row({ status: 'done', answer: 'You went out hot.' });
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                jsonResponse({
                    questions: [answered],
                    suggestions: ['how does this one compare to my usual?'],
                }),
            ),
        );

        const { result } = renderHook(() => useRunQuestions(9));

        await waitFor(() => expect(result.current.questions).toHaveLength(1));
        expect(result.current.questions[0].answer).toBe('You went out hot.');
        expect(result.current.suggestions).toEqual([
            'how does this one compare to my usual?',
        ]);
        expect(result.current.awaitingAnswer).toBe(false);
    });

    it('GETs the activity-scoped url', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(
                jsonResponse({ questions: [], suggestions: [] }),
            );
        vi.stubGlobal('fetch', fetchMock);

        renderHook(() => useRunQuestions(42));

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(fetchMock.mock.calls[0][0]).toBe('/api/activities/42/questions');
    });

    it('leaves the panel empty rather than throwing when the thread fails to load', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response('', { status: 500 })),
        );

        const { result } = renderHook(() => useRunQuestions(9));

        await waitFor(() => expect(result.current.questions).toEqual([]));
        expect(result.current.error).toBeNull();
    });

    it('appends the queued row on a 201 and reports it as awaiting an answer', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(
                jsonResponse({ questions: [], suggestions: [] }),
            )
            .mockResolvedValueOnce(jsonResponse(row(), 201));
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() => useRunQuestions(9));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        await act(async () => {
            await result.current.ask('why did my heart rate drift up?');
        });

        expect(result.current.questions).toHaveLength(1);
        expect(result.current.awaitingAnswer).toBe(true);
        const [, init] = fetchMock.mock.calls[1];
        expect(init.method).toBe('POST');
        expect(init.body).toBe(
            '{"question":"why did my heart rate drift up?"}',
        );
    });

    it('trims the question before sending it', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(
                jsonResponse({ questions: [], suggestions: [] }),
            )
            .mockResolvedValueOnce(jsonResponse(row(), 201));
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() => useRunQuestions(9));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        await act(async () => {
            await result.current.ask('   was it the heat?   ');
        });

        expect(fetchMock.mock.calls[1][1].body).toBe(
            '{"question":"was it the heat?"}',
        );
    });

    it('refuses to send a question under the minimum length', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValue(
                jsonResponse({ questions: [], suggestions: [] }),
            );
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() => useRunQuestions(9));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        let sent: boolean | undefined;
        await act(async () => {
            sent = await result.current.ask('hi');
        });

        expect(sent).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it.each([
        [429, 'rate_limited'],
        [409, 'paused'],
        [422, 'invalid'],
        [500, 'failed'],
    ])('maps a %i response to the %s error', async (status, expected) => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(
                jsonResponse({ questions: [], suggestions: [] }),
            )
            .mockResolvedValueOnce(new Response('{}', { status }));
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() => useRunQuestions(9));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        await act(async () => {
            await result.current.ask('was it the heat?');
        });

        expect(result.current.error).toBe(expected);
        expect(result.current.questions).toEqual([]);
    });

    it('reports a network failure as a failed ask instead of rejecting', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(
                jsonResponse({ questions: [], suggestions: [] }),
            )
            .mockRejectedValueOnce(new Error('offline'));
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() => useRunQuestions(9));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        await act(async () => {
            await result.current.ask('was it the heat?');
        });

        expect(result.current.error).toBe('failed');
    });

    it('polls until the queued answer settles, then stops', async () => {
        vi.useFakeTimers();
        const pending = row({ status: 'processing' });
        const done = row({ status: 'done', answer: 'Heat plus a hot start.' });
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(
                jsonResponse({ questions: [pending], suggestions: [] }),
            )
            .mockResolvedValue(
                jsonResponse({ questions: [done], suggestions: [] }),
            );
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() => useRunQuestions(9));
        await vi.waitFor(() =>
            expect(result.current.awaitingAnswer).toBe(true),
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });

        expect(result.current.questions[0].status).toBe('done');
        expect(result.current.awaitingAnswer).toBe(false);

        const callsAfterSettle = fetchMock.mock.calls.length;
        await act(async () => {
            await vi.advanceTimersByTimeAsync(30000);
        });
        expect(fetchMock.mock.calls.length).toBe(callsAfterSettle);

        vi.useRealTimers();
    });

    it('gives up polling after the cap and exposes a manual re-check', async () => {
        vi.useFakeTimers();
        const pending = row({ status: 'processing' });
        const fetchMock = vi
            .fn()
            .mockResolvedValue(
                jsonResponse({ questions: [pending], suggestions: [] }),
            );
        vi.stubGlobal('fetch', fetchMock);

        const { result } = renderHook(() => useRunQuestions(9));
        await vi.waitFor(() =>
            expect(result.current.awaitingAnswer).toBe(true),
        );

        for (let poll = 0; poll < 41; poll++) {
            await act(async () => {
                await vi.advanceTimersByTimeAsync(3000);
            });
        }

        expect(result.current.stalled).toBe(true);
        const callsWhenStalled = fetchMock.mock.calls.length;

        await act(async () => {
            await vi.advanceTimersByTimeAsync(30000);
        });
        expect(fetchMock.mock.calls.length).toBe(callsWhenStalled);

        await act(async () => {
            result.current.checkAgain();
        });
        await vi.waitFor(() => expect(result.current.stalled).toBe(false));
        expect(fetchMock.mock.calls.length).toBeGreaterThan(callsWhenStalled);

        vi.useRealTimers();
    });

    it('keeps a just-asked question when a thread read that predates it lands afterwards', async () => {
        vi.useFakeTimers();
        const { fetchMock, resolve } = deferredFetch();
        const { result } = renderHook(() => useRunQuestions(9));

        let asked: Promise<boolean> = Promise.resolve(false);
        act(() => {
            asked = result.current.ask('why did my heart rate drift up?');
        });
        await resolve(1, jsonResponse(row({ id: 5 }), 201));
        await act(async () => {
            await asked;
        });
        await resolve(0, jsonResponse({ questions: [], suggestions: [] }));

        expect(result.current.questions.map((q) => q.id)).toEqual([5]);
        expect(result.current.awaitingAnswer).toBe(true);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });
        expect(fetchMock).toHaveBeenCalledTimes(3);

        vi.useRealTimers();
    });

    it('ignores a thread read that lands after a newer one was applied', async () => {
        const { resolve } = deferredFetch();
        const { result } = renderHook(() => useRunQuestions(9));

        act(() => {
            result.current.checkAgain();
        });
        await resolve(
            1,
            jsonResponse({
                questions: [row({ id: 1 })],
                suggestions: ['newer'],
            }),
        );
        await resolve(
            0,
            jsonResponse({
                questions: [row({ id: 1 }), row({ id: 2 })],
                suggestions: ['older'],
            }),
        );

        expect(result.current.questions.map((q) => q.id)).toEqual([1]);
        expect(result.current.suggestions).toEqual(['newer']);
    });

    it('never takes a settled answer back to pending on a later stale read', async () => {
        const { resolve } = deferredFetch();
        const done = row({ status: 'done', answer: 'You went out hot.' });
        const { result } = renderHook(() => useRunQuestions(9));

        await resolve(0, jsonResponse({ questions: [done], suggestions: [] }));
        act(() => {
            result.current.checkAgain();
        });
        await resolve(
            1,
            jsonResponse({
                questions: [row({ status: 'processing' })],
                suggestions: [],
            }),
        );

        expect(result.current.questions[0].status).toBe('done');
        expect(result.current.questions[0].answer).toBe('You went out hot.');
        expect(result.current.awaitingAnswer).toBe(false);
    });

    it('applies nothing from a read that lands after unmount', async () => {
        const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
        const { resolve } = deferredFetch();
        const { result, unmount } = renderHook(() => useRunQuestions(9));

        unmount();
        await resolve(
            0,
            jsonResponse({ questions: [row()], suggestions: ['late'] }),
        );

        expect(result.current.questions).toEqual([]);
        expect(result.current.loaded).toBe(false);
        expect(errors).not.toHaveBeenCalled();
        errors.mockRestore();
    });

    it('starts a fresh thread when the run changes, dropping the old run’s reads', async () => {
        const { resolve } = deferredFetch();
        const { result, rerender } = renderHook(
            ({ id }) => useRunQuestions(id),
            { initialProps: { id: 9 } },
        );

        await resolve(0, jsonResponse({ questions: [row()], suggestions: [] }));
        expect(result.current.questions).toHaveLength(1);

        act(() => {
            result.current.checkAgain();
        });
        rerender({ id: 10 });
        expect(result.current.questions).toEqual([]);

        await resolve(
            1,
            jsonResponse({ questions: [row({ id: 3 })], suggestions: [] }),
        );
        expect(result.current.questions).toEqual([]);

        await resolve(
            2,
            jsonResponse({
                questions: [row({ id: 7, activity_id: 10 })],
                suggestions: [],
            }),
        );
        expect(result.current.questions.map((q) => q.id)).toEqual([7]);
    });
});
