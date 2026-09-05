import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useCoarsePointer } from './useCoarsePointer';

const original = globalThis.matchMedia;

function stubMatchMedia(matches: boolean) {
    const listeners = new Set<() => void>();
    const media = {
        matches,
        addEventListener: (_: string, fn: () => void) => listeners.add(fn),
        removeEventListener: (_: string, fn: () => void) =>
            listeners.delete(fn),
    };
    globalThis.matchMedia = vi.fn(() => media) as unknown as typeof matchMedia;
    return {
        media,
        fire(next: boolean) {
            media.matches = next;
            for (const fn of listeners) fn();
        },
        get listenerCount() {
            return listeners.size;
        },
    };
}

afterEach(() => {
    globalThis.matchMedia = original;
    vi.restoreAllMocks();
});

describe('useCoarsePointer', () => {
    it('reports a coarse pointer on the first render, without waiting for an effect', () => {
        stubMatchMedia(true);
        const { result } = renderHook(() => useCoarsePointer());
        expect(result.current).toBe(true);
    });

    it('reports a fine pointer when the query does not match', () => {
        stubMatchMedia(false);
        const { result } = renderHook(() => useCoarsePointer());
        expect(result.current).toBe(false);
    });

    it('follows the query when the pointer changes', () => {
        const stub = stubMatchMedia(false);
        const { result } = renderHook(() => useCoarsePointer());
        expect(result.current).toBe(false);

        act(() => stub.fire(true));
        expect(result.current).toBe(true);
    });

    it('stops listening when unmounted', () => {
        const stub = stubMatchMedia(false);
        const { unmount } = renderHook(() => useCoarsePointer());
        expect(stub.listenerCount).toBe(1);
        unmount();
        expect(stub.listenerCount).toBe(0);
    });

    it('assumes coarse where matchMedia is missing, keeping the native control', () => {
        // @ts-expect-error deliberately removing the API the hook probes for
        globalThis.matchMedia = undefined;
        const { result } = renderHook(() => useCoarsePointer());
        expect(result.current).toBe(true);
    });
});
