import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { timeOfDayFor, useDawnShift } from './useDawnShift';

describe('timeOfDayFor', () => {
    it.each([
        [4, 'dawn'],
        [6, 'dawn'],
        [7, 'morning'],
        [9, 'morning'],
        [10, 'day'],
        [16, 'day'],
        [17, 'dusk'],
        [19, 'dusk'],
        [20, 'night'],
        [23, 'night'],
        [0, 'night'],
        [3, 'night'],
    ])('classifies hour %i as %s', (hour, expected) => {
        const d = new Date();
        d.setHours(hour, 0, 0, 0);
        expect(timeOfDayFor(d)).toBe(expected);
    });
});

describe('useDawnShift', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        delete document.body.dataset.timeOfDay;
        vi.useRealTimers();
    });

    it('sets data-time-of-day attribute on body matching current hour', () => {
        vi.setSystemTime(new Date(2026, 4, 15, 8, 0, 0));
        renderHook(() => useDawnShift());
        expect(document.body.dataset.timeOfDay).toBe('morning');
    });

    it('clears the attribute on unmount', () => {
        vi.setSystemTime(new Date(2026, 4, 15, 12, 0, 0));
        const { unmount } = renderHook(() => useDawnShift());
        expect(document.body.dataset.timeOfDay).toBe('day');
        unmount();
        expect(document.body.dataset.timeOfDay).toBeUndefined();
    });

    it('re-evaluates time-of-day on its interval tick', () => {
        vi.setSystemTime(new Date(2026, 4, 15, 6, 59, 30));
        const { result } = renderHook(() => useDawnShift());
        expect(result.current).toBe('dawn');

        // Advance system clock past the dawn → morning boundary, then fire
        // the 5-minute polling tick.
        act(() => {
            vi.setSystemTime(new Date(2026, 4, 15, 7, 5, 0));
            vi.advanceTimersByTime(5 * 60 * 1000);
        });
        expect(result.current).toBe('morning');
    });

    it('keeps the same state when the tick lands inside the same bucket', () => {
        vi.setSystemTime(new Date(2026, 4, 15, 12, 0, 0));
        const { result } = renderHook(() => useDawnShift());
        const ref = result.current;
        act(() => {
            vi.advanceTimersByTime(5 * 60 * 1000);
        });
        expect(result.current).toBe(ref);
    });
});
