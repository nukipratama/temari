import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import { getJson } from '@/lib/http';

import { useDayRecommendationToken } from './useDayRecommendationToken';

vi.mock('@/lib/http', () => ({
    getJson: vi.fn(),
}));
afterEach(() => {
    vi.clearAllMocks();
});

const reply = (body: unknown, ok = true) =>
    ({ ok, json: () => Promise.resolve(body) }) as Response;

it('uses the token a day arrived with and fetches nothing', () => {
    const day = { date: '2026-10-31', recommendation_token: 'in-props' };

    const { result } = renderHook(() => useDayRecommendationToken(day));

    expect(result.current).toBe('in-props');
    expect(getJson).not.toHaveBeenCalled();
});

it('fetches the token of a day that arrived without one', async () => {
    vi.mocked(getJson).mockResolvedValue(reply({ token: 'fetched' }));
    const day = { date: '2026-11-02' };

    const { result } = renderHook(() => useDayRecommendationToken(day));

    expect(result.current).toBeUndefined();
    await waitFor(() => expect(result.current).toBe('fetched'));
    expect(getJson).toHaveBeenCalledExactlyOnceWith(
        '/plan/days/2026-11-02/recommendation',
    );
});

it('fetches again when the day is replaced and never shows the old day a stale token', async () => {
    vi.mocked(getJson)
        .mockResolvedValueOnce(reply({ token: 'first' }))
        .mockResolvedValueOnce(reply({ token: 'second' }));
    const { result, rerender } = renderHook(
        ({ day }) => useDayRecommendationToken(day),
        { initialProps: { day: { date: '2026-11-02' } } },
    );
    await waitFor(() => expect(result.current).toBe('first'));

    rerender({ day: { date: '2026-11-02' } });

    expect(result.current).toBeUndefined();
    await waitFor(() => expect(result.current).toBe('second'));
});

it('stays without a token when the fetch fails', async () => {
    vi.mocked(getJson).mockResolvedValue(reply(null, false));
    const day = { date: '2026-11-02' };
    const { result } = renderHook(() => useDayRecommendationToken(day));

    await waitFor(() => expect(getJson).toHaveBeenCalledOnce());

    expect(result.current).toBeUndefined();
});

it('does nothing without a selected day', () => {
    const { result } = renderHook(() => useDayRecommendationToken(null));

    expect(result.current).toBeUndefined();
    expect(getJson).not.toHaveBeenCalled();
});
