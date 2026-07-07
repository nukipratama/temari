import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useCooldownCountdown } from './useCooldownCountdown';

describe('useCooldownCountdown', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('clamps null / negative initial values to zero', () => {
        const { result: nullish } = renderHook(() => useCooldownCountdown(null));
        expect(nullish.current).toBe(0);

        const { result: negative } = renderHook(() => useCooldownCountdown(-5));
        expect(negative.current).toBe(0);
    });

    it('ticks down once per second and stops at zero', () => {
        const { result } = renderHook(() => useCooldownCountdown(3));
        expect(result.current).toBe(3);

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(result.current).toBe(2);

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        expect(result.current).toBe(0);

        // No further ticking once it hits zero.
        act(() => {
            vi.advanceTimersByTime(5000);
        });
        expect(result.current).toBe(0);
    });

    it('clears the interval on unmount', () => {
        const { result, unmount } = renderHook(() => useCooldownCountdown(10));
        expect(result.current).toBe(10);

        unmount();

        // No crash and no state update after unmount.
        act(() => {
            vi.advanceTimersByTime(5000);
        });
        expect(result.current).toBe(10);
    });

    it('restarts the countdown when initialSeconds changes', () => {
        const { result, rerender } = renderHook(({ s }) => useCooldownCountdown(s), {
            initialProps: { s: 2 },
        });
        expect(result.current).toBe(2);

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(result.current).toBe(1);

        rerender({ s: 10 });
        expect(result.current).toBe(10);
    });
});
