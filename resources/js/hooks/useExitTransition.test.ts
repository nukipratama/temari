import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useExitTransition } from './useExitTransition';

describe('useExitTransition', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders nothing while closed', () => {
        const { result } = renderHook(() => useExitTransition(false, 300));
        expect(result.current).toEqual({ rendered: false, closing: false });
    });

    it('renders immediately when opened', () => {
        const { result, rerender } = renderHook(
            ({ open }) => useExitTransition(open, 300),
            { initialProps: { open: false } },
        );

        act(() => rerender({ open: true }));

        expect(result.current).toEqual({ rendered: true, closing: false });
    });

    it('keeps the element mounted and marked closing until the exit window elapses', () => {
        const { result, rerender } = renderHook(
            ({ open }) => useExitTransition(open, 300),
            { initialProps: { open: true } },
        );

        act(() => rerender({ open: false }));
        expect(result.current).toEqual({ rendered: true, closing: true });

        act(() => vi.advanceTimersByTime(299));
        expect(result.current.rendered).toBe(true);

        act(() => vi.advanceTimersByTime(1));
        expect(result.current).toEqual({ rendered: false, closing: false });
    });

    it('cancels a pending unmount when it is reopened mid-exit', () => {
        const { result, rerender } = renderHook(
            ({ open }) => useExitTransition(open, 300),
            { initialProps: { open: true } },
        );

        act(() => rerender({ open: false }));
        act(() => vi.advanceTimersByTime(150));
        act(() => rerender({ open: true }));
        act(() => vi.advanceTimersByTime(300));

        expect(result.current).toEqual({ rendered: true, closing: false });
    });
});
