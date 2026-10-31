import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { defined } from '@/test/defined';

import { useAfterPaint } from './useAfterPaint';

let frames: FrameRequestCallback[];

beforeEach(() => {
    vi.useFakeTimers();
    frames = [];
    vi.stubGlobal('requestAnimationFrame', (cb: FrameRequestCallback) => {
        frames.push(cb);

        return frames.length;
    });
    vi.stubGlobal('cancelAnimationFrame', vi.fn());
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('useAfterPaint', () => {
    it('is false on the committing render and until the frame has painted', () => {
        const { result } = renderHook(() => useAfterPaint());

        expect(result.current).toBe(false);
        expect(frames).toHaveLength(1);

        act(() => {
            vi.runAllTimers();
        });
        expect(result.current).toBe(false);

        act(() => {
            defined(frames[0])(0);
        });
        expect(result.current).toBe(false);
    });

    it('turns true on the task after the frame callback', () => {
        const { result } = renderHook(() => useAfterPaint());

        act(() => {
            defined(frames[0])(0);
            vi.runAllTimers();
        });

        expect(result.current).toBe(true);
    });

    it('cancels the frame and the timer on unmount', () => {
        const { unmount } = renderHook(() => useAfterPaint());
        act(() => {
            defined(frames[0])(0);
        });

        unmount();

        expect(cancelAnimationFrame).toHaveBeenCalledWith(1);
        expect(vi.getTimerCount()).toBe(0);
    });
});
