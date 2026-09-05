import { useCallback, useEffect, useRef, useState } from 'react';

import { getJson, postJson } from '@/lib/http';

export type RunQuestionStatus = 'queued' | 'processing' | 'done' | 'failed';

export interface RunQuestion {
    id: number;
    activity_id: number;
    question: string;
    answer: string | null;
    status: RunQuestionStatus;
    asked_at: string;
}

/** Why an ask did not land. Each maps to its own honest line in the UI. */
export type AskError = 'rate_limited' | 'paused' | 'invalid' | 'failed';

/** Mirrors RunQuestion::MAX_QUESTION_LENGTH. */
export const MAX_QUESTION_LENGTH = 300;

export const MIN_QUESTION_LENGTH = 3;

const POLL_INTERVAL_MS = 3000;

/** A tool-calling answer can block ~90s; stop asking well past that. */
const MAX_POLLS = 40;

const ERROR_BY_STATUS: Readonly<Record<number, AskError>> = {
    409: 'paused',
    422: 'invalid',
    429: 'rate_limited',
};

function isPending(question: RunQuestion): boolean {
    return question.status === 'queued' || question.status === 'processing';
}

export function useRunQuestions(activityId: number) {
    const [questions, setQuestions] = useState<ReadonlyArray<RunQuestion>>([]);
    const [suggestions, setSuggestions] = useState<ReadonlyArray<string>>([]);
    const [asking, setAsking] = useState(false);
    const [error, setError] = useState<AskError | null>(null);
    const [stalled, setStalled] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [tick, setTick] = useState(0);
    const pollsLeftRef = useRef(MAX_POLLS);
    const mountedRef = useRef(true);

    const url = `/api/activities/${activityId}/questions`;

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
        };
    }, []);

    const load = useCallback(async () => {
        const response = await getJson(url);
        if (!mountedRef.current) {
            return;
        }
        if (!response.ok) {
            setLoaded(true);
            return;
        }
        const body: {
            questions?: ReadonlyArray<RunQuestion>;
            suggestions?: ReadonlyArray<string>;
        } = await response.json();
        if (!mountedRef.current) {
            return;
        }
        setQuestions(body.questions ?? []);
        setSuggestions(body.suggestions ?? []);
        setLoaded(true);
    }, [url]);

    useEffect(() => {
        void load().catch(() => {
            if (mountedRef.current) {
                setLoaded(true);
            }
        });
    }, [load]);

    const awaitingAnswer = questions.some(isPending);

    useEffect(() => {
        if (!awaitingAnswer || stalled) {
            return;
        }
        if (pollsLeftRef.current <= 0) {
            setStalled(true);
            return;
        }
        const timer = setTimeout(() => {
            pollsLeftRef.current -= 1;
            void load()
                .catch(() => undefined)
                .finally(() => {
                    if (mountedRef.current) {
                        setTick((n) => n + 1);
                    }
                });
        }, POLL_INTERVAL_MS);

        return () => clearTimeout(timer);
    }, [awaitingAnswer, stalled, load, tick]);

    const ask = useCallback(
        async (text: string): Promise<boolean> => {
            const question = text.trim();
            if (question.length < MIN_QUESTION_LENGTH || asking) {
                return false;
            }
            setAsking(true);
            setError(null);
            try {
                const response = await postJson(url, { question });
                if (response.status === 201) {
                    const row: RunQuestion = await response.json();
                    pollsLeftRef.current = MAX_POLLS;
                    setStalled(false);
                    setQuestions((prev) => [...prev, row]);
                    setTick((n) => n + 1);
                    return true;
                }
                setError(ERROR_BY_STATUS[response.status] ?? 'failed');
                return false;
            } catch {
                setError('failed');
                return false;
            } finally {
                if (mountedRef.current) {
                    setAsking(false);
                }
            }
        },
        [url, asking],
    );

    const checkAgain = useCallback(() => {
        pollsLeftRef.current = MAX_POLLS;
        setStalled(false);
        void load()
            .catch(() => undefined)
            .finally(() => setTick((n) => n + 1));
    }, [load]);

    return {
        questions,
        loaded,
        suggestions,
        ask,
        asking,
        error,
        awaitingAnswer,
        stalled,
        checkAgain,
    };
}
