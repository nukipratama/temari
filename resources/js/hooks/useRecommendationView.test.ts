import { act, renderHook } from '@testing-library/react';
import { createRef } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

import { postJson } from '@/lib/http';

import { useRecommendationView } from './useRecommendationView';

vi.mock('@/lib/http', () => ({
    postJson: vi.fn().mockResolvedValue({ ok: true }),
}));
afterEach(() => {
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

it('records only an intersecting selected panel in a visible document, once', () => {
    let callback: IntersectionObserverCallback;
    const disconnect = vi.fn();
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            constructor(cb: IntersectionObserverCallback) {
                callback = cb;
            }
            observe() {}
            disconnect = disconnect;
        },
    );
    const ref = createRef<HTMLDivElement>();
    ref.current = document.createElement('div');
    const { unmount } = renderHook(() =>
        useRecommendationView(ref, 'signed-advice'),
    );
    act(() =>
        callback(
            [{ isIntersecting: false } as IntersectionObserverEntry],
            {} as IntersectionObserver,
        ),
    );
    expect(postJson).not.toHaveBeenCalled();
    act(() =>
        callback(
            [{ isIntersecting: true } as IntersectionObserverEntry],
            {} as IntersectionObserver,
        ),
    );
    expect(postJson).toHaveBeenCalledWith('/plan/recommendations/shown', {
        token: 'signed-advice',
        observation_id: expect.any(String),
    });
    act(() => document.dispatchEvent(new Event('visibilitychange')));
    expect(postJson).toHaveBeenCalledTimes(1);
    unmount();
    expect(disconnect).toHaveBeenCalledOnce();
});

it('does not acknowledge a fetched row without a rendered panel', () => {
    const ref = createRef<HTMLDivElement>();
    renderHook(() => useRecommendationView(ref, 'fetched-only'));
    expect(postJson).not.toHaveBeenCalled();
});
