import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { showToast, TOAST_DISMISS_MS, useToast } from './useToast';

describe('useToast', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        act(() => {
            vi.runAllTimers();
        });
        vi.useRealTimers();
    });

    it('holds nothing until a toast is shown', () => {
        const { result } = renderHook(() => useToast());

        expect(result.current).toBeNull();
    });

    it('shows a message and clears it after the dismiss delay', () => {
        const { result } = renderHook(() => useToast());

        act(() => showToast('skipped'));
        expect(result.current?.message).toBe('skipped');

        act(() => {
            vi.advanceTimersByTime(TOAST_DISMISS_MS - 1);
        });
        expect(result.current?.message).toBe('skipped');

        act(() => {
            vi.advanceTimersByTime(1);
        });
        expect(result.current).toBeNull();
    });

    it('replaces a toast still on screen and restarts its clock', () => {
        const { result } = renderHook(() => useToast());

        act(() => showToast('skipped'));
        const first = result.current?.id;
        act(() => {
            vi.advanceTimersByTime(TOAST_DISMISS_MS - 1000);
        });
        act(() => showToast('restored'));

        expect(result.current?.message).toBe('restored');
        expect(result.current?.id).not.toBe(first);

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(result.current?.message).toBe('restored');

        act(() => {
            vi.advanceTimersByTime(TOAST_DISMISS_MS - 1000);
        });
        expect(result.current).toBeNull();
    });

    it('stops notifying a component once it unmounts', () => {
        const { result, unmount } = renderHook(() => useToast());
        unmount();

        act(() => showToast('skipped'));

        expect(result.current).toBeNull();
    });
});
