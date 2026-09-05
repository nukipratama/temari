import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useIsChartDark } from './useIsChartDark';

function setTheme(theme: 'light' | 'dark') {
    act(() => {
        document.documentElement.dataset.theme = theme;
    });
}

describe('useIsChartDark', () => {
    afterEach(() => {
        delete document.documentElement.dataset.theme;
    });

    it('reads the current ground on mount rather than waiting for a change', () => {
        document.documentElement.dataset.theme = 'dark';
        const { result } = renderHook(() => useIsChartDark());
        expect(result.current).toBe(true);
    });

    it('is false when the ground is light', () => {
        document.documentElement.dataset.theme = 'light';
        const { result } = renderHook(() => useIsChartDark());
        expect(result.current).toBe(false);
    });

    it('flips live when data-theme changes on <html>', async () => {
        document.documentElement.dataset.theme = 'light';
        const { result } = renderHook(() => useIsChartDark());
        expect(result.current).toBe(false);

        setTheme('dark');
        await vi.waitFor(() => expect(result.current).toBe(true));

        setTheme('light');
        await vi.waitFor(() => expect(result.current).toBe(false));
    });

    it('stops observing after unmount', () => {
        document.documentElement.dataset.theme = 'light';
        const { result, unmount } = renderHook(() => useIsChartDark());
        unmount();

        setTheme('dark');

        expect(result.current).toBe(false);
    });
});
